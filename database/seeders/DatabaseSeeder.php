<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UsuarioSeeder::class,
            EmpresaSeeder::class,
            ReuniaoSeeder::class,
            DocumentoSeeder::class,
            TreinamentoSeeder::class,
            ProjetoSeeder::class,
            ComercialSeeder::class,
            MarketingSeeder::class,
            PesquisaSeeder::class,
        ]);
    }
}
