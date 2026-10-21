<?php

namespace App\Support;

use App\Models\ContaGoogle;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * GOOGLE AGENDA
 * O caminho até a agenda de uma conta Gmail: o consentimento OAuth, que dá o
 * token de atualização, e a leitura dos eventos da agenda principal com ele.
 * Só leitura: o HC Brain nunca escreve na agenda de ninguém.
 */
final class GoogleAgenda
{
    /** A HC trabalha no horário de Brasília; o servidor roda em UTC. */
    public const FUSO = 'America/Sao_Paulo';

    private const AUTORIZACAO = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const PERFIL = 'https://openidconnect.googleapis.com/v1/userinfo';

    private const EVENTOS = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    private const AGENDA_PRINCIPAL = 'https://www.googleapis.com/calendar/v3/users/me/calendarList/primary';

    private const ESCOPOS = ['openid', 'email', 'profile', 'https://www.googleapis.com/auth/calendar.readonly'];

    /**
     * As cores que a tela do Google Agenda mostra para cada `colorId` de
     * evento. A API devolve só o número; os tons antigos que ela documenta não
     * são os que aparecem para quem usa a agenda.
     */
    public const CORES_DOS_EVENTOS = [
        '1' => '#7986cb', // Lavanda
        '2' => '#33b679', // Sálvia
        '3' => '#8e24aa', // Uva
        '4' => '#e67c73', // Flamingo
        '5' => '#f6bf26', // Banana
        '6' => '#f4511e', // Tangerina
        '7' => '#039be5', // Pavão
        '8' => '#616161', // Grafite
        '9' => '#3f51b5', // Mirtilo
        '10' => '#0b8043', // Manjericão
        '11' => '#d50000', // Tomate
    ];

    public static function configurada(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public static function enderecoDeRetorno(): string
    {
        return config('services.google.redirect') ?: route('google.retorno');
    }

    /**
     * `prompt=consent` faz o Google devolver o token de atualização mesmo para
     * uma conta que já tinha autorizado antes — sem ele, reconectar não traria
     * token novo.
     */
    public static function urlDeAutorizacao(string $estado): string
    {
        return self::AUTORIZACAO.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => self::enderecoDeRetorno(),
            'response_type' => 'code',
            'scope' => implode(' ', self::ESCOPOS),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $estado,
        ]);
    }

    /**
     * Troca o código do retorno pelos tokens e descobre de quem é a conta.
     *
     * @return array{email: string, nome: string, refresh_token: string|null}
     */
    public static function trocarCodigo(string $codigo): array
    {
        $tokens = Http::asForm()->timeout(15)->post(self::TOKEN, [
            'code' => $codigo,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => self::enderecoDeRetorno(),
            'grant_type' => 'authorization_code',
        ])->throw();

        $perfil = Http::withToken((string) $tokens->json('access_token'))
            ->timeout(15)
            ->get(self::PERFIL)
            ->throw();

        $email = (string) $perfil->json('email');

        return [
            'email' => $email,
            'nome' => (string) ($perfil->json('name') ?: $email),
            'refresh_token' => $tokens->json('refresh_token'),
        ];
    }

    /**
     * O token de acesso dura uma hora; a cada sincronização sai um novo.
     *
     * @throws RuntimeException quando a conta revogou o acesso ou o token expirou
     */
    public static function tokenDeAcesso(ContaGoogle $conta): string
    {
        $resposta = Http::asForm()->timeout(15)->post(self::TOKEN, [
            'refresh_token' => $conta->refresh_token,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'grant_type' => 'refresh_token',
        ]);

        if ($resposta->json('error') === 'invalid_grant') {
            throw new RuntimeException('O Google recusou o acesso desta conta: conecte-a de novo.');
        }

        return (string) $resposta->throw()->json('access_token');
    }

    /**
     * A cor da agenda principal, que é a dos eventos sem cor própria. Sem ela
     * a reunião só fica com a cor do status, então uma falha aqui não impede
     * a leitura.
     */
    public static function corDaAgenda(string $token): ?string
    {
        $resposta = Http::withToken($token)->timeout(15)->get(self::AGENDA_PRINCIPAL);
        $cor = $resposta->successful() ? $resposta->json('backgroundColor') : null;

        return is_string($cor) && preg_match('/^#[0-9a-f]{6}$/i', $cor) ? strtolower($cor) : null;
    }

    /**
     * Eventos da agenda principal no intervalo, com as recorrências já abertas
     * em ocorrências e os cancelados incluídos — é por eles que uma reunião
     * desmarcada no Google também é desmarcada aqui.
     *
     * @return list<array<string, mixed>>
     */
    public static function eventos(string $token, CarbonInterface $de, CarbonInterface $ate, ?CarbonInterface $alteradosDesde = null): array
    {
        $eventos = [];
        $pagina = null;

        do {
            $resposta = Http::withToken($token)
                ->timeout(20)
                ->get(self::EVENTOS, array_filter([
                    'singleEvents' => 'true',
                    'showDeleted' => 'true',
                    'maxResults' => 250,
                    'timeMin' => $de->toRfc3339String(),
                    'timeMax' => $ate->toRfc3339String(),
                    'updatedMin' => $alteradosDesde?->toRfc3339String(),
                    'pageToken' => $pagina,
                ]))
                ->throw();

            array_push($eventos, ...$resposta->json('items', []));
            $pagina = $resposta->json('nextPageToken');
        } while ($pagina);

        return $eventos;
    }
}
