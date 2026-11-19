<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContaYoutubeAnalytics;
use App\Support\GoogleAgenda;
use App\Support\YouTubeAnalytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class YoutubeAnalyticsContaController extends Controller
{
    public function show(): JsonResponse
    {
        $conta = YouTubeAnalytics::contaAtiva();

        return response()->json([
            'configurada' => YouTubeAnalytics::configurada(),
            'conectada' => $conta !== null,
            'conta' => $conta === null ? null : [
                'id' => $conta->id,
                'email' => $conta->email,
                'nome' => $conta->nome,
                'channelId' => $conta->channel_id,
                'sincronizadaEm' => $conta->sincronizada_em
                    ?->setTimezone(GoogleAgenda::FUSO)
                    ->format('d/m/Y H:i'),
                'erro' => $conta->erro,
            ],
        ]);
    }

    public function destroy(Request $request): Response
    {
        abort_unless($request->user()->ehAdministrador(), 403, 'Só um administrador desconecta o YouTube Analytics.');

        ContaYoutubeAnalytics::query()->delete();

        return response()->noContent();
    }
}
