<?php

namespace App\Console\Commands;

use App\Models\ContaGoogle;
use App\Models\Empresa;
use App\Models\Reuniao;
use App\Models\Socio;
use App\Models\User;
use App\Support\GoogleAgenda;
use Illuminate\Console\Command;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Traz para a agenda do HC Brain os eventos com Meet das contas Google
 * conectadas. O Google manda na data, no horário e no link; o que a equipe
 * registra aqui — resumo, decisões, próximos passos, tipo e empresa escolhida
 * à mão — fica como está. É seguro repetir: a reunião é reconhecida pela conta
 * e pelo id do evento, então nunca é duplicada.
 */
class SincronizarAgendas extends Command
{
    protected $signature = 'hc:sincronizar-agendas
        {--completa : Relê a janela inteira, não só o que mudou desde a última leitura}';

    protected $description = 'Traz as reuniões com Meet das agendas Google conectadas';

    private const DIAS_PARA_TRAS = 60;

    private const DIAS_PARA_FRENTE = 180;

    /** Folga contra o relógio do Google e o do servidor não baterem. */
    private const MARGEM_EM_MINUTOS = 5;

    private const TRAVA_EM_SEGUNDOS = 600;

    /**
     * Cores de evento que a equipe usa no Google Agenda para marcar o tipo da
     * reunião: Banana para as internas, Sálvia para as de abertura.
     */
    private const TIPO_PELA_COR = [
        '5' => Reuniao::INTERNA,
        '2' => 'Abertura',
    ];

    /**
     * Palavras no título (já normalizadas) que apontam o tipo sem repetir o
     * nome oficial — "kickoff" vira Abertura, "demo" vira Comercial, etc.
     *
     * @var array<string, string>
     */
    private const SINONIMOS_DO_TIPO = [
        'kickoff' => 'Abertura',
        'onboarding' => 'Abertura',
        'demo' => 'Comercial',
        'demonstracao' => 'Comercial',
        'proposta' => 'Comercial',
        '1 1' => 'Alinhamento',
        'follow up' => 'Alinhamento',
        'followup' => 'Alinhamento',
        'financeiro' => 'Financeira',
    ];

    /** Terminações que não ajudam a reconhecer a empresa no título do evento. */
    private const SUFIXOS = ['ltda', 'me', 'epp', 'eireli', 'sa', 'ss', 'slu'];

    /**
     * Domínios de e-mail pessoal: dois sócios com @gmail.com não dizem nada
     * sobre de qual empresa é a reunião.
     */
    private const DOMINIOS_PESSOAIS = [
        'gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.com.br', 'outlook.com', 'outlook.com.br',
        'live.com', 'msn.com', 'yahoo.com', 'yahoo.com.br', 'icloud.com', 'me.com', 'uol.com.br',
        'bol.com.br', 'terra.com.br', 'ig.com.br', 'globo.com', 'globomail.com', 'protonmail.com', 'proton.me',
    ];

    /** @var array<string, string> e-mail do sócio => id da empresa */
    private array $empresaPorEmail = [];

    /** @var array<string, string> domínio do e-mail dos sócios => id da empresa */
    private array $empresaPorDominio = [];

    /** @var array<string, true> domínios da própria HC, que estão em toda reunião */
    private array $dominiosDaCasa = [];

    /** @var array<string, string> nome da empresa já normalizado => id da empresa */
    private array $empresaPorNome = [];

    /** A cor da agenda que está sendo lida, para os eventos sem cor própria. */
    private ?string $corDaAgenda = null;

    public function handle(): int
    {
        $contas = ContaGoogle::all();

        if ($contas->isEmpty()) {
            $this->components->info('Nenhuma conta Google conectada.');

            return self::SUCCESS;
        }

        // Sem o cliente OAuth nenhuma conta é lida; a tela precisa dizer isso
        // em vez de ficar aguardando a primeira leitura para sempre.
        if (! GoogleAgenda::configurada()) {
            $mensagem = 'GOOGLE_CLIENT_ID e GOOGLE_CLIENT_SECRET não estão definidos no servidor.';
            ContaGoogle::query()->update(['erro' => $mensagem]);
            $this->components->error($mensagem);

            return self::FAILURE;
        }

        // A trava expira sozinha: uma leitura interrompida no meio, como num
        // reinício do contêiner, não impede as próximas.
        $trava = Cache::lock('hc:sincronizar-agendas', self::TRAVA_EM_SEGUNDOS);

        if (! $trava->get()) {
            $this->components->info('Já há uma leitura das agendas em andamento.');

            return self::SUCCESS;
        }

        try {
            $this->carregarEmpresas();

            $falhas = $contas->reject(fn (ContaGoogle $conta) => $this->sincronizar($conta))->count();
        } finally {
            $trava->release();
        }

        return $falhas === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function sincronizar(ContaGoogle $conta): bool
    {
        $inicio = now();

        try {
            $token = GoogleAgenda::tokenDeAcesso($conta);
            $this->corDaAgenda = GoogleAgenda::corDaAgenda($token);
            $eventos = GoogleAgenda::eventos(
                $token,
                $inicio->copy()->subDays(self::DIAS_PARA_TRAS),
                $inicio->copy()->addDays(self::DIAS_PARA_FRENTE),
                $this->option('completa') ? null : $conta->sincronizada_em?->copy()->subMinutes(self::MARGEM_EM_MINUTOS),
            );
        } catch (RuntimeException $erro) {
            return $this->falhou($conta, $erro->getMessage());
        } catch (HttpClientException) {
            return $this->falhou($conta, 'O Google não respondeu. A próxima sincronização tenta de novo.');
        } catch (Throwable $erro) {
            return $this->falhouSemExplicacao($conta, $erro);
        }

        try {
            $contagem = $this->gravarEventos($conta, $eventos, $inicio);
        } catch (Throwable $erro) {
            return $this->falhouSemExplicacao($conta, $erro);
        }

        // Quantos eventos vieram e quantos têm Meet explica uma leitura que
        // deu certo mas não trouxe reunião nenhuma.
        $comMeet = count(array_filter(
            $eventos,
            fn (array $evento) => ($evento['status'] ?? null) !== 'cancelled' && $this->linkDoMeet($evento) !== null,
        ));

        $this->components->info(sprintf(
            '%s: %d eventos lidos, %d com Meet — %d novas e %d atualizadas.',
            $conta->email, count($eventos), $comMeet, $contagem['nova'], $contagem['atualizada'],
        ));

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $eventos
     * @return array{nova: int, atualizada: int}
     */
    private function gravarEventos(ContaGoogle $conta, array $eventos, Carbon $inicio): array
    {
        $existentes = $conta->reunioes()
            ->whereIn('google_evento_id', array_column($eventos, 'id'))
            ->get()
            ->keyBy('google_evento_id');

        $contagem = ['nova' => 0, 'atualizada' => 0];

        DB::transaction(function () use ($conta, $eventos, $existentes, $inicio, &$contagem) {
            foreach ($eventos as $evento) {
                $resultado = $this->gravar($conta, $evento, $existentes->get($evento['id']));

                if ($resultado !== null) {
                    $contagem[$resultado]++;
                }
            }

            $conta->update(['sincronizada_em' => $inicio, 'erro' => null]);
        });

        return $contagem;
    }

    private function falhou(ContaGoogle $conta, string $mensagem): bool
    {
        $conta->update(['erro' => $mensagem]);
        $this->components->error("{$conta->email}: {$mensagem}");

        return false;
    }

    /** O detalhe vai para o log; a tela mostra o bastante para saber que algo quebrou. */
    private function falhouSemExplicacao(ContaGoogle $conta, Throwable $erro): bool
    {
        report($erro);

        return $this->falhou($conta, Str::limit('A leitura da agenda falhou: '.$erro->getMessage(), 250));
    }

    /**
     * @param  array<string, mixed>  $evento
     * @return 'nova'|'atualizada'|null
     */
    private function gravar(ContaGoogle $conta, array $evento, ?Reuniao $existente): ?string
    {
        if (($evento['status'] ?? null) === 'cancelled') {
            if ($existente === null) {
                return null;
            }

            $trocas = [];

            if ($existente->status === 'Agendada') {
                $trocas['status'] = 'Cancelada';
            }

            // Mesmo cancelado: tira o Alinhamento inventado e guarda o título do Google.
            if ($existente->tipo === 'Alinhamento' && $this->tipoDo($evento) === Reuniao::OUTRA) {
                $trocas['tipo'] = Reuniao::OUTRA;
            }

            $titulo = $this->tituloDo($evento);
            if ($titulo !== null && $titulo !== $existente->titulo) {
                $trocas['titulo'] = $titulo;
            }

            if ($trocas === []) {
                return null;
            }

            $existente->update($trocas);

            return 'atualizada';
        }

        $link = $this->linkDoMeet($evento);
        $momento = $this->inicioDo($evento);

        // Sem Meet o evento não é reunião com cliente. Se o link foi tirado
        // depois, a reunião já registrada fica como está.
        if ($link === null || $momento === null) {
            return null;
        }

        $quando = [
            'data' => $momento->toDateString(),
            'horario' => isset($evento['start']['dateTime']) ? $momento->format('H:i') : null,
            'link_meet' => $link,
            'cor' => GoogleAgenda::CORES_DOS_EVENTOS[(string) ($evento['colorId'] ?? '')] ?? $this->corDaAgenda,
            // O nome no Google acompanha renomeações; sem empresa, a agenda usa ele.
            'titulo' => $this->tituloDo($evento),
        ];

        $tipoPelaCor = self::TIPO_PELA_COR[(string) ($evento['colorId'] ?? '')] ?? null;
        $tipoPeloTitulo = $this->tipoDo($evento);

        if ($existente === null) {
            $tipo = $tipoPelaCor ?? $tipoPeloTitulo;

            Reuniao::create([
                ...$quando,
                'conta_google_id' => $conta->id,
                'google_evento_id' => $evento['id'],
                'empresa_id' => $tipo === Reuniao::INTERNA ? null : $this->empresaDo($evento),
                'tipo' => $tipo,
                'responsavel' => $conta->nome,
                'participantes' => $this->participantesDo($evento),
                'resumo' => $this->resumoDo($evento),
                'decisoes' => [],
                'proximos_passos' => [],
                'status' => $momento->isFuture() ? 'Agendada' : 'Concluída',
            ]);

            return 'nova';
        }

        // Cor do Google manda. Título com pista clara atualiza o tipo. Sem pista,
        // preserva o que a equipe gravou — exceto o Alinhamento inventado pelo
        // default antigo do sync, que vira Outra.
        if ($tipoPelaCor !== null) {
            $tipo = $tipoPelaCor;
        } elseif ($tipoPeloTitulo !== Reuniao::OUTRA) {
            $tipo = $tipoPeloTitulo;
        } elseif ($existente->tipo === 'Alinhamento') {
            $tipo = Reuniao::OUTRA;
        } else {
            $tipo = $existente->tipo;
        }

        $existente->fill([
            ...$quando,
            'tipo' => $tipo,
            'empresa_id' => $existente->empresa_id ?? ($tipo === Reuniao::INTERNA ? null : $this->empresaDo($evento)),
            // Desmarcada no Google e remarcada depois volta a valer.
            'status' => $existente->status === 'Cancelada'
                ? ($momento->isFuture() ? 'Agendada' : 'Concluída')
                : $existente->status,
        ]);

        if (! $existente->isDirty()) {
            return null;
        }

        $existente->save();

        return 'atualizada';
    }

    /** @param  array<string, mixed>  $evento */
    private function linkDoMeet(array $evento): ?string
    {
        $links = [
            $evento['hangoutLink'] ?? null,
            ...array_column($evento['conferenceData']['entryPoints'] ?? [], 'uri'),
        ];

        foreach ($links as $link) {
            if (is_string($link) && str_starts_with($link, 'https://meet.google.com/')) {
                return $link;
            }
        }

        return null;
    }

    /**
     * Evento de dia inteiro só tem a data; o de horário marcado vem com o fuso
     * de quem criou e é convertido para o de Brasília.
     *
     * @param  array<string, mixed>  $evento
     */
    private function inicioDo(array $evento): ?Carbon
    {
        if (isset($evento['start']['dateTime'])) {
            return Carbon::parse($evento['start']['dateTime'])->setTimezone(GoogleAgenda::FUSO);
        }

        if (isset($evento['start']['date'])) {
            return Carbon::parse($evento['start']['date'], GoogleAgenda::FUSO);
        }

        return null;
    }

    /**
     * O e-mail de um convidado que é sócio de uma empresa é a pista mais
     * segura; depois vem o domínio do e-mail de um convidado igual ao de um
     * sócio, que pega quem trabalha na empresa mas não está cadastrado; por
     * último, o nome da empresa escrito no título. Na dúvida entre duas
     * empresas, a reunião fica sem empresa para alguém escolher.
     *
     * @param  array<string, mixed>  $evento
     */
    private function empresaDo(array $evento): ?string
    {
        $emails = collect($evento['attendees'] ?? [])
            ->reject(fn (array $convidado) => $convidado['resource'] ?? false)
            ->map(fn (array $convidado) => Str::lower(trim((string) ($convidado['email'] ?? ''))))
            ->filter();

        foreach ($emails as $email) {
            if (isset($this->empresaPorEmail[$email])) {
                return $this->empresaPorEmail[$email];
            }
        }

        foreach ($emails as $email) {
            $empresa = $this->empresaPorDominio[$this->dominioDe($email) ?? ''] ?? null;

            if ($empresa !== null) {
                return $empresa;
            }
        }

        $titulo = ' '.$this->normalizar($evento['summary'] ?? '').' ';
        $encontrada = null;
        $tamanho = 0;

        foreach ($this->empresaPorNome as $nome => $empresa) {
            if (strlen($nome) > $tamanho && str_contains($titulo, " {$nome} ")) {
                $encontrada = $empresa;
                $tamanho = strlen($nome);
            }
        }

        return $encontrada;
    }

    /** @param  array<string, mixed>  $evento */
    private function tipoDo(array $evento): string
    {
        $titulo = ' '.$this->normalizar($evento['summary'] ?? '').' ';

        foreach (Reuniao::TIPOS as $tipo) {
            if ($tipo === Reuniao::OUTRA) {
                continue;
            }

            if (str_contains($titulo, ' '.$this->normalizar($tipo).' ')) {
                return $tipo;
            }
        }

        foreach (self::SINONIMOS_DO_TIPO as $sinonimo => $tipo) {
            if (str_contains($titulo, ' '.$sinonimo.' ')) {
                return $tipo;
            }
        }

        return Reuniao::OUTRA;
    }

    /**
     * Quem foi convidado, menos a própria dona da agenda e as salas.
     *
     * @param  array<string, mixed>  $evento
     * @return list<string>
     */
    private function participantesDo(array $evento): array
    {
        return collect($evento['attendees'] ?? [])
            ->reject(fn (array $convidado) => ($convidado['self'] ?? false) || ($convidado['resource'] ?? false))
            ->map(fn (array $convidado) => (string) ($convidado['displayName'] ?? $convidado['email'] ?? ''))
            ->filter()
            ->values()
            ->all();
    }

    /** @param  array<string, mixed>  $evento */
    private function tituloDo(array $evento): ?string
    {
        $titulo = trim((string) ($evento['summary'] ?? ''));

        return $titulo !== '' ? Str::limit($titulo, 255) : null;
    }

    /** @param  array<string, mixed>  $evento */
    private function resumoDo(array $evento): string
    {
        $descricao = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $evento['description'] ?? ''))));
        $resumo = trim(($evento['summary'] ?? '')."\n\n".$descricao);

        return $resumo !== '' ? Str::limit($resumo, 2000) : 'Reunião importada do Google Agenda.';
    }

    /**
     * Um e-mail ou um nome que aponta para mais de uma empresa não serve de
     * pista, então fica de fora.
     */
    private function carregarEmpresas(): void
    {
        $socios = Socio::query()->where('email', 'like', '%@%')->get(['empresa_id', 'email']);

        $this->empresaPorEmail = $this->semAmbiguidade(
            $socios->map(fn (Socio $socio) => [Str::lower(trim($socio->email)), $socio->empresa_id])
        );

        // Os domínios da equipe da HC aparecem em quase toda reunião e nunca
        // apontam para um cliente, mesmo que algum sócio use o mesmo.
        $this->dominiosDaCasa = User::query()->pluck('email')
            ->merge(ContaGoogle::query()->pluck('email'))
            ->map(fn (string $email) => Str::lower(Str::after($email, '@')))
            ->flip()
            ->map(fn () => true)
            ->all();

        $this->empresaPorDominio = $this->semAmbiguidade(
            $socios
                ->map(fn (Socio $socio) => [$this->dominioDe(Str::lower(trim($socio->email))), $socio->empresa_id])
                ->filter(fn (array $par) => $par[0] !== null)
        );

        $this->empresaPorNome = $this->semAmbiguidade(
            Empresa::query()->get(['id', 'nome'])
                ->map(fn (Empresa $empresa) => [$this->nomeParaBusca($empresa->nome), $empresa->id])
                ->filter(fn (array $par) => strlen($par[0]) >= 4)
        );
    }

    /**
     * @param  iterable<array{0: string, 1: string}>  $pares
     * @return array<string, string>
     */
    private function semAmbiguidade(iterable $pares): array
    {
        $mapa = [];
        $ambiguas = [];

        foreach ($pares as [$chave, $empresa]) {
            if (isset($mapa[$chave]) && $mapa[$chave] !== $empresa) {
                $ambiguas[$chave] = true;
            }

            $mapa[$chave] = $empresa;
        }

        return array_diff_key($mapa, $ambiguas);
    }

    /** O domínio do e-mail, ou nulo quando ele não identifica empresa nenhuma. */
    private function dominioDe(string $email): ?string
    {
        $dominio = Str::after($email, '@');

        if ($dominio === $email || $dominio === '' || in_array($dominio, self::DOMINIOS_PESSOAIS, true) || isset($this->dominiosDaCasa[$dominio])) {
            return null;
        }

        return $dominio;
    }

    private function nomeParaBusca(string $nome): string
    {
        $palavras = explode(' ', $this->normalizar($nome));

        while (count($palavras) > 1 && in_array(end($palavras), self::SUFIXOS, true)) {
            array_pop($palavras);
        }

        return implode(' ', $palavras);
    }

    private function normalizar(string $texto): string
    {
        return Str::of($texto)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();
    }
}
