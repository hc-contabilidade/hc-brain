<?php

namespace Database\Seeders;

use App\Models\Empresa;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Importa a carteira da planilha `storage/app/empresas.xlsx`: a aba Empresas
 * vira a empresa e a aba Sócios, ligada pelo código, vira o sócio dela.
 *
 * Roda a cada inicialização do contêiner, então é seguro repetir: empresa cujo
 * nome já está na carteira não é recriada nem tem o status trocado. Código e
 * CNPJ/CPF vazios são preenchidos, inclusive na cópia "RAZÃO (código)" que a
 * sincronização grava quando o nome simples já existia.
 */
class ImportEmpresasSeeder extends Seeder
{
    /** @var array<string, string> status na planilha => cor da etiqueta */
    private const ETIQUETAS = [
        'Ativo' => 'green',
        'Inativo' => 'gray',
        'Bloqueado' => 'red',
    ];

    private const RASCUNHO = 'Rascunho de Empresa';

    private const NAO_INFORMADO = '—';

    public function run(): void
    {
        $filePath = storage_path('app/empresas.xlsx');

        if (! file_exists($filePath)) {
            $this->command->error("Arquivo não encontrado em: {$filePath}");

            return;
        }

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $planilha = $reader->load($filePath);

        $sociosPorEmpresa = $this->sociosPorEmpresa($planilha);
        $existentes = Empresa::query()->get(['id', 'nome']);
        $nomesNaCarteira = $existentes->pluck('id', 'nome')->all();
        $idsOcupados = $existentes->pluck('id')->flip()->all();

        $empresas = [];
        $socios = [];
        $nomesDaPlanilha = [];
        $documentosPorNome = [];
        $agora = now();

        foreach ($this->linhas($planilha, 'Empresas', 'D') as [$codigo, $cnpj, $razaoSocial, $status]) {
            $razaoSocial = trim((string) $razaoSocial);
            $codigo = trim((string) $codigo);

            if ($razaoSocial === '' || $razaoSocial === self::RASCUNHO) {
                continue;
            }

            $documento = $this->documentoDe($cnpj);
            $this->lembrarDocumento($documentosPorNome, $razaoSocial, $codigo, $documento);
            if ($codigo !== '') {
                $this->lembrarDocumento($documentosPorNome, "{$razaoSocial} ({$codigo})", $codigo, $documento);
            }

            $nome = $this->nomeUnico($razaoSocial, $codigo, $nomesDaPlanilha);

            if ($nome === null) {
                continue;
            }

            $nomesDaPlanilha[$nome] = $codigo;
            $this->lembrarDocumento($documentosPorNome, $nome, $codigo, $documento);

            if (isset($nomesNaCarteira[$nome])) {
                continue;
            }

            $id = $this->chaveLivre($nome, $idsOcupados);
            $idsOcupados[$id] = true;
            $status = trim((string) $status) ?: 'Ativo';

            $empresas[] = [
                'id' => $id,
                'codigo' => $codigo !== '' ? $codigo : null,
                'documento' => $documento,
                'documento_tipo' => $this->documentoTipoDe($documento),
                'nome' => $nome,
                'setor' => 'Não informado',
                'status' => $status,
                'status_tag' => self::ETIQUETAS[$status] ?? 'gray',
                'created_at' => $agora,
                'updated_at' => $agora,
            ];

            $socios[] = [
                'empresa_id' => $id,
                ...$this->socio($sociosPorEmpresa["{$codigo}|{$razaoSocial}"] ?? []),
                'created_at' => $agora,
                'updated_at' => $agora,
            ];
        }

        $atualizacoes = $this->documentosEmFalta($documentosPorNome, $agora);

        DB::transaction(function () use ($empresas, $socios, $atualizacoes) {
            foreach (array_chunk($empresas, 500) as $lote) {
                DB::table('empresas')->insert($lote);
            }

            foreach (array_chunk($socios, 500) as $lote) {
                DB::table('socios')->insert($lote);
            }

            foreach ($atualizacoes as $atualizacao) {
                DB::table('empresas')->where('id', $atualizacao['id'])->update($atualizacao['dados']);
            }
        });

        $this->command->info(count($empresas).' empresas importadas da planilha.');
    }

    /**
     * Preenche CNPJ/CPF vazio pelo código da planilha, mesmo quando o nome
     * no Brain é o fantasia da automação e não a razão social.
     */
    public function completarDocumentos(): void
    {
        $filePath = storage_path('app/empresas.xlsx');

        if (! is_file($filePath)) {
            return;
        }

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $planilha = $reader->load($filePath);
        $documentosPorNome = [];

        foreach ($this->linhas($planilha, 'Empresas', 'D') as [$codigo, $cnpj, $razaoSocial]) {
            $razaoSocial = trim((string) $razaoSocial);
            $codigo = trim((string) $codigo);

            if ($razaoSocial === '' || $razaoSocial === self::RASCUNHO) {
                continue;
            }

            $documento = $this->documentoDe($cnpj);
            $this->lembrarDocumento($documentosPorNome, $razaoSocial, $codigo, $documento);
            if ($codigo !== '') {
                $this->lembrarDocumento($documentosPorNome, "{$razaoSocial} ({$codigo})", $codigo, $documento);
            }
        }

        $atualizacoes = $this->documentosEmFalta($documentosPorNome, now());

        DB::transaction(function () use ($atualizacoes) {
            foreach ($atualizacoes as $atualizacao) {
                DB::table('empresas')->where('id', $atualizacao['id'])->update($atualizacao['dados']);
            }
        });
    }

    /**
     * Só as colunas usadas, sem formatação nem fórmulas: é o que mantém a
     * leitura rápida mesmo com a carteira inteira na planilha.
     *
     * @return list<list<mixed>>
     */
    private function linhas(Spreadsheet $planilha, string $aba, string $ultimaColuna): array
    {
        $folha = $planilha->getSheetByName($aba);

        if ($folha === null || $folha->getHighestDataRow() < 2) {
            return [];
        }

        return $folha->rangeToArray("A2:{$ultimaColuna}".$folha->getHighestDataRow(), null, false, false);
    }

    /**
     * O código sozinho não basta: há linhas de exemplo na aba Empresas que
     * reaproveitam o código de uma empresa real, e elas não podem herdar o
     * sócio dela.
     *
     * @return array<string, list<array{nome: string, categoria: string, administrador: bool, email: string, telefone: string}>> "código|razão social" => sócios
     */
    private function sociosPorEmpresa(Spreadsheet $planilha): array
    {
        $porEmpresa = [];

        foreach ($this->linhas($planilha, 'Sócios', 'I') as [$codigo, , $razaoSocial, $nome, , $categoria, $administrador, $email, $telefone]) {
            if (blank($nome)) {
                continue;
            }

            $porEmpresa[$codigo.'|'.trim((string) $razaoSocial)][] = [
                'nome' => trim((string) $nome),
                'categoria' => trim((string) $categoria) ?: 'Sócio',
                'administrador' => trim((string) $administrador) === 'Sim',
                'email' => $this->preenchido($email),
                'telefone' => $this->preenchido($telefone),
            ];
        }

        return $porEmpresa;
    }

    /**
     * A mesma razão social em códigos diferentes (filiais, por exemplo) se
     * diferencia pelo código; a mesma linha repetida na planilha entra uma vez.
     *
     * @param  array<string, string>  $nomesDaPlanilha  nome => código
     */
    private function nomeUnico(string $razaoSocial, string $codigo, array $nomesDaPlanilha): ?string
    {
        if (($nomesDaPlanilha[$razaoSocial] ?? $codigo) === $codigo) {
            return isset($nomesDaPlanilha[$razaoSocial]) ? null : $razaoSocial;
        }

        $comCodigo = "{$razaoSocial} ({$codigo})";

        return isset($nomesDaPlanilha[$comCodigo]) ? null : $comCodigo;
    }

    /**
     * Mesma regra do `Chave::apartirDe`, mas contra os ids já reservados nesta
     * execução, porque as empresas só são gravadas no fim, todas juntas.
     *
     * @param  array<string, bool>  $idsOcupados
     */
    private function chaveLivre(string $nome, array $idsOcupados): string
    {
        $base = Str::slug($nome) ?: 'empresa';
        $chave = $base;
        $sufixo = 2;

        while (isset($idsOcupados[$chave])) {
            $chave = "{$base}-{$sufixo}";
            $sufixo++;
        }

        return $chave;
    }

    /**
     * Um sócio por empresa: o administrador quando há, senão o primeiro da
     * lista. Sem sócio na planilha a empresa ainda ganha um registro, porque
     * a lista de clientes lê `socio->nome`.
     *
     * @param  list<array{nome: string, categoria: string, administrador: bool, email: string, telefone: string}>  $daEmpresa
     * @return array{nome: string, cargo: string, email: string, telefone: string, participacao: string, desde: string}
     */
    private function socio(array $daEmpresa): array
    {
        usort($daEmpresa, fn (array $a, array $b) => $b['administrador'] <=> $a['administrador']);
        $socio = $daEmpresa[0] ?? null;

        return [
            'nome' => $socio['nome'] ?? 'Sócio não cadastrado',
            'cargo' => match (true) {
                $socio === null => self::NAO_INFORMADO,
                $socio['administrador'] => 'Sócio administrador',
                default => $socio['categoria'],
            },
            'email' => $socio['email'] ?? self::NAO_INFORMADO,
            'telefone' => $socio['telefone'] ?? self::NAO_INFORMADO,
            'participacao' => self::NAO_INFORMADO,
            'desde' => self::NAO_INFORMADO,
        ];
    }

    /**
     * @param  array<string, array{codigo: string, documento: ?string}>  $documentosPorNome
     */
    private function lembrarDocumento(array &$documentosPorNome, string $nome, string $codigo, ?string $documento): void
    {
        if (isset($documentosPorNome[$nome])) {
            return;
        }

        $documentosPorNome[$nome] = ['codigo' => $codigo, 'documento' => $documento];
    }

    /**
     * @param  array<string, array{codigo: string, documento: ?string}>  $documentosPorNome
     * @return list<array{id: string, dados: array<string, mixed>}>
     */
    private function documentosEmFalta(array $documentosPorNome, Carbon $agora): array
    {
        if ($documentosPorNome === []) {
            return [];
        }

        $porCodigo = [];
        foreach ($documentosPorNome as $linha) {
            if ($linha['codigo'] !== '' && $linha['documento'] !== null && ! isset($porCodigo[$linha['codigo']])) {
                $porCodigo[$linha['codigo']] = $linha['documento'];
            }
        }

        $atualizacoes = [];

        foreach (Empresa::query()->get(['id', 'nome', 'codigo', 'documento']) as $empresa) {
            $codigo = filled($empresa->codigo) ? trim((string) $empresa->codigo) : $this->codigoNoNome($empresa->nome);
            $linha = $documentosPorNome[$empresa->nome] ?? null;
            $documento = is_array($linha) ? $linha['documento'] : null;
            if ($documento === null && $codigo !== null) {
                $documento = $porCodigo[$codigo] ?? null;
            }
            $codigoPlanilha = is_array($linha) ? $linha['codigo'] : $codigo;
            $dados = [];

            if (blank($empresa->codigo) && filled($codigoPlanilha)) {
                $dados['codigo'] = $codigoPlanilha;
            }

            if (blank($empresa->documento) && $documento !== null) {
                $dados['documento'] = $documento;
                $dados['documento_tipo'] = $this->documentoTipoDe($documento);
            }

            if ($dados === []) {
                continue;
            }

            $dados['updated_at'] = $agora;
            $atualizacoes[] = ['id' => $empresa->id, 'dados' => $dados];
        }

        return $atualizacoes;
    }

    private function codigoNoNome(string $nome): ?string
    {
        if (preg_match('/\((\d+)\)\s*$/', $nome, $encontrado) !== 1) {
            return null;
        }

        return $encontrado[1];
    }

    private function documentoDe(mixed $valor): ?string
    {
        $texto = is_int($valor) || is_float($valor) ? sprintf('%.0f', (float) $valor) : (string) $valor;
        $digitos = preg_replace('/\D+/', '', $texto) ?? '';

        if ($digitos === '') {
            return null;
        }

        $digitos = match (true) {
            strlen($digitos) <= 11 => str_pad($digitos, 11, '0', STR_PAD_LEFT),
            strlen($digitos) <= 14 => str_pad($digitos, 14, '0', STR_PAD_LEFT),
            default => null,
        };

        return $digitos;
    }

    /** @return 'CNPJ'|'CPF'|null */
    private function documentoTipoDe(?string $documento): ?string
    {
        return match (strlen((string) $documento)) {
            14 => 'CNPJ',
            11 => 'CPF',
            default => null,
        };
    }

    private function preenchido(mixed $valor): string
    {
        $valor = trim((string) $valor);

        return in_array($valor, ['', '-'], true) ? self::NAO_INFORMADO : $valor;
    }
}
