<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\Processo;
use App\Models\Projeto;
use App\Models\Reuniao;
use App\Models\Treinamento;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * RESPOSTAS DA SOFIA
 * A Sofia lê exatamente a mesma base das outras telas: empresas, reuniões e
 * conteúdos de treinamento. Por isso a resposta é montada no servidor, onde o
 * banco está — a tela só envia a pergunta e mostra o texto que volta.
 */
final class Sofia
{
    private const PALAVRAS_TREINAMENTO = ['treinamento', 'curso', 'trilha', 'manual', 'fluxograma'];

    private const PALAVRAS_CONTAGEM = ['quantas', 'quantos', 'total de', 'número de', 'numero de'];

    /** Quantas empresas uma resposta lista antes de resumir o resto em um número. */
    private const LIMITE_DA_LISTA = 30;

    /** O que cada palavra de contagem responde: chave em Contadores e o texto. */
    private const ROTULOS_CONTAGEM = [
        'empresas' => ['empresa', 'empresas cadastradas na carteira'],
        'reunioes' => ['reuni', 'reuniões registradas'],
        'documentos' => ['documento', 'documentos e pastas no banco de conhecimento'],
        'usuarios' => ['usuário', 'pessoas com acesso ao HC Brain'],
        'fontes' => ['fonte', 'fontes vinculadas às empresas'],
    ];

    public static function responder(string $pergunta): string
    {
        $texto = Str::lower($pergunta);

        // Só id e nome para procurar: a carteira tem mais de mil empresas, e as
        // relações só valem a consulta para a que foi citada.
        $empresa = Empresa::query()
            ->get(['id', 'nome'])
            ->first(fn (Empresa $registro) => str_contains($texto, Str::lower($registro->nome)));

        if ($empresa !== null) {
            return self::sobreEmpresa($empresa->load(['socio', 'fontes', 'treinamentos']), $texto);
        }

        if (self::pedeTreinamento($texto)) {
            return self::sobreTreinamentos($texto);
        }

        if (str_contains($texto, 'projeto')) {
            return self::sobreProjetos();
        }

        if (str_contains($texto, 'processo') || str_contains($texto, 'fluxo')) {
            return self::sobreProcessos();
        }

        $tipoReuniao = collect(Reuniao::TIPOS)
            ->first(fn (string $tipo) => str_contains($texto, Str::lower($tipo)));

        if ($tipoReuniao !== null) {
            return self::sobreTipoDeReuniao($tipoReuniao);
        }

        // "quantas reuniões temos?" caía na resposta genérica: a pergunta é
        // por um número, não por um tipo de reunião.
        if (self::pedeContagem($texto)) {
            return self::sobreONumeroDa($texto);
        }

        if (str_contains($texto, 'pendente') || str_contains($texto, 'agendad')) {
            return self::sobreAgendadas();
        }

        if (str_contains($texto, 'sócio') || str_contains($texto, 'socio')) {
            $lista = Empresa::with('socio')->orderBy('nome')->limit(self::LIMITE_DA_LISTA)->get()
                ->map(fn (Empresa $e) => "• {$e->nome}: {$e->socio->nome} ({$e->socio->cargo})")
                ->implode("\n");

            return "Sócios responsáveis por empresa:\n{$lista}".self::restantes(Empresa::count());
        }

        if (str_contains($texto, 'empresa') || str_contains($texto, 'cliente')) {
            $total = Empresa::count();
            $lista = Empresa::orderBy('nome')->limit(self::LIMITE_DA_LISTA)->get()
                ->map(fn (Empresa $e) => "• {$e->nome} — {$e->setor} — {$e->status}")
                ->implode("\n");

            return "Temos {$total} empresas cadastradas na base:\n{$lista}".self::restantes($total);
        }

        return 'Encontrei informações relacionadas na base da HC. Há registros sobre empresas, '
            .'sócios, propostas comerciais, reuniões (abertura, transferência, dúvidas, comercial, '
            .'alinhamento e financeira), documentos, projetos, processos internos e conteúdos do '
            .'sistema de treinamento. Pergunte por uma empresa, um tipo de reunião, um processo, '
            .'um treinamento — ou por quantos registros existem de cada coisa.';
    }

    private static function restantes(int $total): string
    {
        $restantes = $total - self::LIMITE_DA_LISTA;

        return $restantes > 0
            ? "\n…e mais {$restantes}. Pergunte pelo nome de uma empresa para ver os detalhes dela."
            : '';
    }

    private static function pedeTreinamento(string $texto): bool
    {
        foreach (self::PALAVRAS_TREINAMENTO as $palavra) {
            if (str_contains($texto, $palavra)) {
                return true;
            }
        }

        return false;
    }

    private static function pedeContagem(string $texto): bool
    {
        foreach (self::PALAVRAS_CONTAGEM as $palavra) {
            if (str_contains($texto, $palavra)) {
                return true;
            }
        }

        return false;
    }

    /** Responde "quantos X temos?" com os números que o Início também mostra. */
    private static function sobreONumeroDa(string $texto): string
    {
        $numeros = Contadores::doInicio();

        foreach (self::ROTULOS_CONTAGEM as $chave => [$palavra, $frase]) {
            if (str_contains($texto, $palavra)) {
                return "A base tem {$numeros[$chave]} {$frase}.";
            }
        }

        return "A base da HC tem hoje {$numeros['empresas']} empresas, "
            ."{$numeros['reunioes']} reuniões, {$numeros['documentos']} documentos, "
            ."{$numeros['treinamentos']} conteúdos de capacitação, "
            ."{$numeros['projetos']} projetos e {$numeros['processos']} processos "
            ."documentados, com {$numeros['usuarios']} pessoas com acesso.";
    }

    private static function sobreProjetos(): string
    {
        $projetos = Projeto::orderBy('ordem')->get();

        if ($projetos->isEmpty()) {
            return 'Não há projetos registrados na base.';
        }

        $lista = $projetos
            ->map(fn (Projeto $p) => "• {$p->nome} — {$p->status}, {$p->progresso}% "
                ."(responsável: {$p->responsavel}, prazo {$p->prazo->format('d/m/Y')})")
            ->implode("\n");

        return "Há {$projetos->count()} projetos na carteira da HC:\n{$lista}";
    }

    private static function sobreProcessos(): string
    {
        $processos = Processo::orderBy('ordem')->get();

        if ($processos->isEmpty()) {
            return 'Não há processos documentados na base.';
        }

        $lista = $processos
            ->map(fn (Processo $p) => '• '.$p->nome." — {$p->area}, ".count($p->etapas)
                ." etapas (responsável: {$p->responsavel})")
            ->implode("\n");

        return "A HC tem {$processos->count()} processos documentados:\n{$lista}";
    }

    private static function sobreEmpresa(Empresa $empresa, string $texto): string
    {
        if (self::pedeTreinamento($texto)) {
            return self::treinamentosDaEmpresa($empresa);
        }

        if (str_contains($texto, 'sócio') || str_contains($texto, 'socio')) {
            return "O sócio responsável pela {$empresa->nome} é {$empresa->socio->nome} "
                ."({$empresa->socio->cargo}), participação de {$empresa->socio->participacao}. "
                ."Contato: {$empresa->socio->email}.";
        }

        if (str_contains($texto, 'fonte') || str_contains($texto, 'documento')) {
            $lista = $empresa->fontes->map(fn ($fonte) => "• {$fonte->nome}")->implode("\n");

            return "A {$empresa->nome} tem {$empresa->fontes->count()} fonte(s) vinculada(s) "
                ."diretamente:\n".($lista !== '' ? $lista : 'nenhuma fonte cadastrada ainda.');
        }

        $reunioes = $empresa->reunioes()->get();

        if (str_contains($texto, 'reuni')) {
            if ($reunioes->isEmpty()) {
                return "Ainda não há reuniões registradas para a {$empresa->nome}.";
            }

            $lista = $reunioes->take(5)
                ->map(fn (Reuniao $r) => "• {$r->tipo} — {$r->data->format('d/m/Y')} ({$r->status})")
                ->implode("\n");

            return "A {$empresa->nome} tem {$reunioes->count()} reunião(ões) registradas, "
                ."sócio responsável {$empresa->socio->nome}:\n{$lista}";
        }

        $ultima = $reunioes->first();
        $resumoUltima = $ultima !== null
            ? "{$ultima->tipo} em {$ultima->data->format('d/m/Y')}"
            : 'nenhuma registrada';

        return "A {$empresa->nome} ({$empresa->setor}) está com status \"{$empresa->status}\". "
            ."Sócio responsável: {$empresa->socio->nome}. Última reunião: {$resumoUltima}.";
    }

    private static function treinamentosDaEmpresa(Empresa $empresa): string
    {
        if ($empresa->treinamentos->isEmpty()) {
            return "Não encontrei conteúdos de treinamento relacionados à {$empresa->nome}.";
        }

        $lista = $empresa->treinamentos
            ->map(function (Treinamento $item) {
                $processo = $item->processo !== null ? " · processo: {$item->processo}" : '';

                return "• {$item->titulo} — {$item->tipo}{$processo}";
            })
            ->implode("\n");

        return "Conteúdos de capacitação relacionados à {$empresa->nome}:\n{$lista}";
    }

    private static function sobreTreinamentos(string $texto): string
    {
        $palavras = collect(preg_split('/\s+/u', $texto) ?: [])
            ->filter(fn (string $palavra) => Str::length($palavra) > 3);

        $encontrados = Treinamento::orderBy('ordem')->get()
            ->filter(function (Treinamento $item) use ($palavras) {
                $conteudo = Str::lower(implode(' ', [
                    $item->titulo, $item->descricao, $item->categoria, $item->tipo, $item->processo ?? '',
                ]));

                return $palavras->contains(fn (string $palavra) => str_contains($conteudo, $palavra));
            })
            ->take(6);

        if ($encontrados->isEmpty()) {
            return 'A base conhece cursos, trilhas, manuais e fluxogramas do sistema de treinamento. '
                .'Você pode perguntar por um tema, processo ou empresa específica.';
        }

        $lista = $encontrados
            ->map(fn (Treinamento $item) => "• {$item->titulo} — {$item->tipo} · {$item->categoria}")
            ->implode("\n");

        return "Encontrei conteúdos de capacitação na base da HC:\n{$lista}";
    }

    private static function sobreTipoDeReuniao(string $tipo): string
    {
        /** @var Collection<int, Reuniao> $reunioes */
        $reunioes = Reuniao::with('empresa')->where('tipo', $tipo)->orderByDesc('data')->get();

        if ($reunioes->isEmpty()) {
            return "Não encontrei reuniões do tipo \"{$tipo}\" na base.";
        }

        $lista = $reunioes
            ->map(fn (Reuniao $r) => "• {$r->nomeDaEmpresa()} — {$r->data->format('d/m/Y')} ({$r->status})")
            ->implode("\n");

        return "Encontrei {$reunioes->count()} reunião(ões) de {$tipo}:\n{$lista}";
    }

    private static function sobreAgendadas(): string
    {
        $pendentes = Reuniao::with('empresa')->where('status', 'Agendada')->orderByDesc('data')->get();

        if ($pendentes->isEmpty()) {
            return 'Não há reuniões agendadas pendentes no momento.';
        }

        $lista = $pendentes
            ->map(fn (Reuniao $r) => "• {$r->nomeDaEmpresa()} — {$r->tipo} em {$r->data->format('d/m/Y')}")
            ->implode("\n");

        return "Há {$pendentes->count()} reunião(ões) agendada(s):\n{$lista}";
    }
}
