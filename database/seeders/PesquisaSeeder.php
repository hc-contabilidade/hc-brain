<?php

namespace Database\Seeders;

use App\Models\ResultadoPesquisa;
use App\Models\SugestaoSofia;
use Illuminate\Database\Seeder;

/** Índice da pesquisa e as sugestões iniciais da Sofia. */
class PesquisaSeeder extends Seeder
{
    private const RESULTADOS = [
        ['Treinamento', 'Atendimento', 'A Importância do Atendimento', 'Curso de atendimento relacionado à comunicação e relacionamento com clientes.', 'Curso • Iniciante • Sistema de treinamento'],
        ['Treinamento', 'Comercial', 'Abertura, Regularização e Encerramento', 'Curso relacionado ao processo de abertura, regularização e encerramento de empresas.', 'Curso • Iniciante • Sistema de treinamento'],
        ['Treinamento', 'Regime tributário', 'Demonstrativo Simples Nacional', 'Curso relacionado ao demonstrativo do Simples Nacional.', 'Curso • Intermediário • Sistema de treinamento'],
        ['Treinamento', 'Integração', 'Processo com o Cliente', 'Manual com orientações para condução do processo junto ao cliente.', 'Manual • Sistema de treinamento'],
        ['Treinamento', 'Comercial', 'Trilha do Comercial', 'Trilha com 4 cursos para atividades comerciais e relacionamento.', 'Trilha • Intermediário • Sistema de treinamento'],
        ['Treinamento', 'Legalização', 'TESTE MATHEUS', 'Fluxograma relacionado à legalização.', 'Fluxograma • Sistema de treinamento'],
        ['Pessoa', 'Comercial', 'Flávia Silva — Empresa X', 'Gerente Comercial, sócia responsável pela Empresa X. Demonstrou interesse no serviço de automação.', 'Cadastro de cliente • Atualizado hoje'],
        ['Pessoa', 'Operações', 'João Pereira — Empresa Y', 'Diretor de Operações e sócio responsável pela Empresa Y.', 'Cadastro de cliente'],
        ['Pessoa', 'Projetos', 'Mariana Costa — Empresa Z', 'Coordenadora de Projetos e sócia responsável pela Empresa Z.', 'Cadastro de cliente'],
        ['Documento', 'Comercial', 'Proposta comercial — Empresa X', 'Documento relacionado ao projeto de automação.', 'PDF • Atualizado ontem'],
        ['Reunião', 'Comercial', 'Reunião comercial — Empresa X — 28/08/2026', 'Revisão de demandas, decisões e próximos passos do projeto de automação.', 'Reunião comercial • Concluída'],
        ['Reunião', 'Operações', 'Reunião de abertura — Empresa X — 10/07/2026', 'Boas-vindas, apresentação da equipe HC e alinhamento de expectativas.', 'Reunião de abertura • Concluída'],
        ['Reunião', 'Operações', 'Reunião de transferência — Empresa Y — 20/08/2026', 'Transferência da conta da equipe comercial para a equipe de operações.', 'Reunião de transferência • Concluída'],
        ['Reunião', 'Projetos', 'Reunião de dúvidas — Empresa Z — 30/08/2026', 'Esclarecimentos sobre o escopo do projeto de base de conhecimento.', 'Reunião de dúvidas • Concluída'],
        ['Projeto', 'Operações', 'Projeto de automação', 'Projeto para reduzir tarefas repetitivas e melhorar o fluxo comercial da Empresa X.', 'Status: em andamento'],
        ['Documento', 'Operações', 'Fluxos e procedimentos da HC', 'Manual com os processos internos da empresa.', 'DOCX • Atualizado em 01/09/2026'],
    ];

    /** O ícone é um nome da Lucide — o mesmo vocabulário do resto da interface. */
    private const SUGESTOES = [
        ['calendar-days', 'Quais reuniões estão agendadas hoje?'],
        ['building-2', 'Quais reuniões tivemos com a Empresa X?'],
        ['users', 'Quem é o sócio responsável pela Empresa Y?'],
        ['file-text', 'Quais documentos temos sobre a Empresa X?'],
        ['circle-dollar-sign', 'Qual o honorário atual da Empresa X?'],
        ['rocket', 'Quais projetos estão em andamento?'],
    ];

    public function run(): void
    {
        ResultadoPesquisa::query()->delete();
        foreach (self::RESULTADOS as $ordem => [$tipo, $area, $titulo, $texto, $meta]) {
            ResultadoPesquisa::create(compact('tipo', 'area', 'titulo', 'texto', 'meta', 'ordem'));
        }

        SugestaoSofia::query()->delete();
        foreach (self::SUGESTOES as $ordem => [$icone, $texto]) {
            SugestaoSofia::create(compact('icone', 'texto', 'ordem'));
        }
    }
}
