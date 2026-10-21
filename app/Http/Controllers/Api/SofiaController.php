<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EnviarAudioSofiaRequest;
use App\Http\Requests\PerguntarSofiaRequest;
use App\Models\SugestaoSofia;
use App\Support\Sofia;
use App\Support\Transcricao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Throwable;

class SofiaController extends Controller
{
    public const AVISO_SEM_CHAVE = 'A Sofia não está configurada. Defina OPENAI_API_KEY no ambiente para conversar com ela.';

    public const AVISO_INDISPONIVEL = 'Não consegui consultar a base agora. Tente novamente em instantes.';

    public function sugestoes(): JsonResponse
    {
        $sugestoes = SugestaoSofia::orderBy('ordem')->get()
            ->map(fn (SugestaoSofia $item) => ['icon' => $item->icone, 'text' => $item->texto]);

        return response()->json($sugestoes);
    }

    public function perguntar(PerguntarSofiaRequest $request): JsonResponse
    {
        if (! Sofia::configurada()) {
            return response()->json(['message' => self::AVISO_SEM_CHAVE], 503);
        }

        try {
            $resposta = Sofia::responder($request->pergunta(), $request->historico());
        } catch (Throwable $excecao) {
            report($excecao);

            return response()->json(['message' => self::AVISO_INDISPONIVEL], 502);
        }

        return response()->json(['resposta' => $resposta]);
    }

    public function audio(EnviarAudioSofiaRequest $request): JsonResponse
    {
        if (! Transcricao::configurada()) {
            return response()->json([
                'message' => 'A transcrição de arquivos de áudio não está configurada. Defina OPENAI_API_KEY no ambiente para enviar áudios gravados ou anexados.',
            ], 503);
        }

        $arquivo = $request->file('audio');

        if (! $arquivo instanceof UploadedFile) {
            return response()->json([
                'message' => 'Escolha ou grave um áudio para enviar.',
            ], 422);
        }

        try {
            $texto = Transcricao::deArquivo($arquivo);
        } catch (Throwable $excecao) {
            report($excecao);

            return response()->json([
                'message' => 'Não consegui transcrever o áudio agora. Tente novamente em instantes.',
            ], 502);
        }

        if ($texto === '') {
            return response()->json([
                'message' => 'Não consegui entender o áudio. Grave de novo ou escreva a pergunta.',
            ], 422);
        }

        if (! Sofia::configurada()) {
            return response()->json(['message' => self::AVISO_SEM_CHAVE], 503);
        }

        try {
            $resposta = Sofia::responder($texto, $request->historico());
        } catch (Throwable $excecao) {
            report($excecao);

            return response()->json(['message' => self::AVISO_INDISPONIVEL], 502);
        }

        return response()->json([
            'transcricao' => $texto,
            'resposta' => $resposta,
        ]);
    }
}
