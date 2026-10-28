<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EnviarAudioSofiaRequest;
use App\Http\Requests\PerguntarSofiaRequest;
use App\Models\SofiaConversa;
use App\Models\SugestaoSofia;
use App\Support\Sofia;
use App\Support\SofiaContexto;
use App\Support\Transcricao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class SofiaController extends Controller
{
    public const AVISO_SEM_CHAVE = 'A Sofia não está configurada. Defina no ambiente a chave de pelo menos um provedor (OPENAI_API_KEY, GEMINI_API_KEY, OPENROUTER_API_KEY, DEEPSEEK_API_KEY, XAI_API_KEY, MISTRAL_API_KEY ou GROQ_API_KEY).';

    public const AVISO_INDISPONIVEL = 'Não consegui consultar a base agora. Tente novamente em instantes.';

    public function sugestoes(): JsonResponse
    {
        $sugestoes = SugestaoSofia::orderBy('ordem')->get()
            ->map(fn (SugestaoSofia $item) => ['icon' => $item->icone, 'text' => $item->texto]);

        return response()->json($sugestoes);
    }

    public function conversas(Request $request): JsonResponse
    {
        $conversas = SofiaConversa::query()
            ->where('user_id', $request->user()->id)
            ->with('mensagens')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (SofiaConversa $conversa) => $conversa->paraTela())
            ->values();

        return response()->json($conversas);
    }

    public function importar(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'conversas' => ['required', 'array', 'max:50'],
            'conversas.*.id' => ['required', 'uuid'],
            'conversas.*.titulo' => ['required', 'string', 'max:120'],
            'conversas.*.atualizadoEm' => ['sometimes', 'integer'],
            'conversas.*.mensagens' => ['required', 'array', 'max:100'],
            'conversas.*.mensagens.*.autor' => ['required', 'in:user,bot'],
            'conversas.*.mensagens.*.texto' => ['required', 'string', 'max:4000'],
            'conversas.*.mensagens.*.voz' => ['sometimes', 'boolean'],
        ]);

        $usuario = $request->user();
        $modo = in_array($request->input('modo'), SofiaContexto::MODOS, true)
            ? $request->input('modo')
            : 'search';

        foreach ($validado['conversas'] as $item) {
            $jaExiste = SofiaConversa::query()->where('id', $item['id'])->exists();
            if ($jaExiste) {
                continue;
            }

            $conversa = SofiaConversa::create([
                'id' => $item['id'],
                'user_id' => $usuario->id,
                'titulo' => $item['titulo'],
                'modo' => $modo,
            ]);

            foreach ($item['mensagens'] as $mensagem) {
                $conversa->mensagens()->create([
                    'autor' => $mensagem['autor'],
                    'texto' => $mensagem['texto'],
                    'voz' => (bool) ($mensagem['voz'] ?? false),
                ]);
            }
        }

        return $this->conversas($request);
    }

    public function destroy(Request $request, string $conversa): Response
    {
        $modelo = SofiaConversa::query()
            ->where('user_id', $request->user()->id)
            ->where('id', $conversa)
            ->firstOrFail();

        $modelo->delete();

        return response()->noContent();
    }

    public function perguntar(PerguntarSofiaRequest $request): JsonResponse|StreamedResponse
    {
        SofiaContexto::definir($request->user(), $request->modo());

        if (! Sofia::configurada()) {
            return response()->json(['message' => self::AVISO_SEM_CHAVE], 503);
        }

        if ($this->querFluxo($request)) {
            return $this->perguntarEmFluxo($request);
        }

        try {
            $resultado = Sofia::responderNaConversa(
                $request->user(),
                $request->pergunta(),
                $request->modo(),
                $request->conversaId(),
                $request->historico(),
                false,
                null,
                $request->modelo(),
            );
        } catch (HttpExceptionInterface $excecao) {
            throw $excecao;
        } catch (Throwable $excecao) {
            report($excecao);

            return response()->json(['message' => self::AVISO_INDISPONIVEL], 502);
        }

        return response()->json([
            'resposta' => $resultado['resposta'],
            'conversa' => $resultado['conversa']->paraTela(),
        ]);
    }

    public function audio(EnviarAudioSofiaRequest $request): JsonResponse
    {
        SofiaContexto::definir($request->user(), $request->modo());

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
            $resultado = Sofia::responderNaConversa(
                $request->user(),
                $texto,
                $request->modo(),
                $request->conversaId(),
                $request->historico(),
                true,
                null,
                $request->modelo(),
            );
        } catch (HttpExceptionInterface $excecao) {
            throw $excecao;
        } catch (Throwable $excecao) {
            report($excecao);

            return response()->json(['message' => self::AVISO_INDISPONIVEL], 502);
        }

        return response()->json([
            'transcricao' => $texto,
            'resposta' => $resultado['resposta'],
            'conversa' => $resultado['conversa']->paraTela(),
        ]);
    }

    private function querFluxo(Request $request): bool
    {
        return str_contains(strtolower((string) $request->header('Accept')), 'text/event-stream');
    }

    private function perguntarEmFluxo(PerguntarSofiaRequest $request): StreamedResponse
    {
        return response()->stream(function () use ($request): void {
            $enviar = function (array $evento): void {
                echo 'data: '.json_encode($evento, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            try {
                $resultado = Sofia::responderNaConversa(
                    $request->user(),
                    $request->pergunta(),
                    $request->modo(),
                    $request->conversaId(),
                    $request->historico(),
                    false,
                    function (string $token) use ($enviar): void {
                        $enviar(['token' => $token]);
                    },
                    $request->modelo(),
                );

                $enviar([
                    'done' => true,
                    'resposta' => $resultado['resposta'],
                    'conversa' => $resultado['conversa']->paraTela(),
                ]);
            } catch (Throwable $excecao) {
                report($excecao);
                $enviar(['erro' => self::AVISO_INDISPONIVEL]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
