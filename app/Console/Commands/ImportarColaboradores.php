<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Importa colaboradores da planilha `storage/app/colaboradores.xlsx` (export
 * da tela de colaboradores da automação HC) como acessos do HC Brain.
 *
 * É seguro repetir: quem já tem o e-mail no Brain só atualiza nome, perfil e
 * área; a senha fica como está. Novos entram com `hc.senha_semeada`.
 */
class ImportarColaboradores extends Command
{
    protected $signature = 'hc:importar-colaboradores
        {--arquivo= : Caminho da planilha; o padrão é storage/app/colaboradores.xlsx}';

    protected $description = 'Importa colaboradores de uma planilha Excel como usuários do HC Brain';

    /** @var array<string, string> função na planilha => perfil no Brain */
    private const PERFIS = [
        'administrador' => 'Administrador',
        'admin' => 'Administrador',
        'gestor' => 'Gestor',
        'funcionário' => 'Colaborador',
        'funcionario' => 'Colaborador',
        'colaborador' => 'Colaborador',
    ];

    public function handle(): int
    {
        $caminho = $this->option('arquivo') ?: storage_path('app/colaboradores.xlsx');

        if (! is_file($caminho)) {
            $this->components->error("Arquivo não encontrado em: {$caminho}");
            $this->components->info('Exporte a lista de colaboradores da automação e salve como storage/app/colaboradores.xlsx');

            return self::FAILURE;
        }

        $reader = IOFactory::createReaderForFile($caminho);
        $reader->setReadDataOnly(true);
        $planilha = $reader->load($caminho);

        $linhas = $this->linhas($planilha);

        if ($linhas === []) {
            $this->components->error('A planilha não tem linhas de colaborador (precisa de cabeçalho Nome, Email, Função, Departamento).');

            return self::FAILURE;
        }

        $senha = (string) config('hc.senha_semeada');
        $novos = 0;
        $atualizados = 0;
        $ignorados = 0;

        foreach ($linhas as $indice => $linha) {
            $numero = $indice + 2;
            $nome = trim((string) ($linha['nome'] ?? ''));
            $email = Str::lower(trim((string) ($linha['email'] ?? '')));
            $perfil = $this->perfilDe($linha['funcao'] ?? null);
            $area = $this->areaDe($linha['departamento'] ?? null);

            $erros = Validator::make(
                ['nome' => $nome, 'email' => $email, 'perfil' => $perfil, 'area' => $area],
                [
                    'nome' => ['required', 'string', 'max:255'],
                    'email' => ['required', 'email', 'max:255'],
                    'perfil' => ['required', Rule::in(User::PERFIS)],
                    'area' => ['required', Rule::in(User::AREAS)],
                ],
            )->errors();

            if ($erros->isNotEmpty()) {
                $this->components->warn("Linha {$numero} ignorada: ".$erros->first());
                $ignorados++;

                continue;
            }

            $existente = User::query()->where('email', $email)->first();

            if ($existente !== null) {
                $existente->fill([
                    'name' => $nome,
                    'perfil' => $perfil,
                    'area' => $area,
                    'status' => 'Ativo',
                ])->save();
                $atualizados++;

                continue;
            }

            User::query()->create([
                'name' => $nome,
                'email' => $email,
                'password' => $senha,
                'perfil' => $perfil,
                'area' => $area,
                'status' => 'Ativo',
            ]);
            $novos++;
        }

        $this->components->info("{$novos} novos, {$atualizados} atualizados, {$ignorados} ignorados.");
        if ($novos > 0) {
            $this->components->warn('Novos acessos entram com a senha de HC_SENHA_SEMEADA (ou 123456). Peça para trocar no primeiro login.');
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array{nome: ?string, email: ?string, funcao: ?string, departamento: ?string}>
     */
    private function linhas(Spreadsheet $planilha): array
    {
        $folha = $planilha->getSheet(0);

        if ($folha->getHighestDataRow() < 2) {
            return [];
        }

        $cabecalhos = $folha->rangeToArray('A1:'.$folha->getHighestDataColumn().'1', null, false, false)[0] ?? [];
        $mapa = $this->mapaDeColunas($cabecalhos);

        if ($mapa === null) {
            return [];
        }

        $brutas = $folha->rangeToArray(
            'A2:'.$folha->getHighestDataColumn().$folha->getHighestDataRow(),
            null,
            false,
            false,
        );

        return array_map(fn (array $linha) => [
            'nome' => $linha[$mapa['nome']] ?? null,
            'email' => $linha[$mapa['email']] ?? null,
            'funcao' => $linha[$mapa['funcao']] ?? null,
            'departamento' => $linha[$mapa['departamento']] ?? null,
        ], $brutas);
    }

    /**
     * @param  list<mixed>  $cabecalhos
     * @return array{nome: int, email: int, funcao: int, departamento: int}|null
     */
    private function mapaDeColunas(array $cabecalhos): ?array
    {
        $indice = [];

        foreach ($cabecalhos as $posicao => $rotulo) {
            $chave = $this->normalizarCabecalho((string) $rotulo);
            if ($chave !== '') {
                $indice[$chave] = $posicao;
            }
        }

        $nome = $indice['nome'] ?? null;
        $email = $indice['email'] ?? $indice['e-mail'] ?? null;
        $funcao = $indice['funcao'] ?? $indice['função'] ?? $indice['perfil'] ?? null;
        $departamento = $indice['departamento'] ?? $indice['secao'] ?? $indice['seção'] ?? $indice['area'] ?? $indice['área'] ?? null;

        if ($nome === null || $email === null || $funcao === null || $departamento === null) {
            return null;
        }

        return [
            'nome' => $nome,
            'email' => $email,
            'funcao' => $funcao,
            'departamento' => $departamento,
        ];
    }

    private function normalizarCabecalho(string $rotulo): string
    {
        return Str::of($rotulo)->lower()->ascii()->trim()->toString();
    }

    private function perfilDe(mixed $funcao): ?string
    {
        $chave = $this->normalizarCabecalho((string) $funcao);

        return self::PERFIS[$chave] ?? null;
    }

    private function areaDe(mixed $departamento): string
    {
        $valor = trim((string) $departamento);

        if ($valor === '' || $valor === '—' || $valor === '-') {
            return 'Operações';
        }

        $chave = $this->normalizarCabecalho($valor);

        foreach (User::AREAS as $area) {
            if ($this->normalizarCabecalho($area) === $chave) {
                return $area;
            }
        }

        // Nomes próximos da automação que não batem letra a letra com o Brain.
        return match ($chave) {
            'gestao', 'administrativo', 'rh', 'recursos humanos' => 'Gestão',
            'comercial', 'vendas' => 'Comercial',
            'operacoes', 'operacao' => 'Operações',
            'atendimento', 'suporte', 'cs' => 'Atendimento',
            'financeiro', 'contabilidade', 'fiscal' => 'Financeiro',
            'projetos', 'ti', 'tecnologia' => 'Projetos',
            default => 'Operações',
        };
    }
}
