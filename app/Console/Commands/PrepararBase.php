<?php

namespace App\Console\Commands;

use App\Models\MarketingConteudo;
use App\Models\MarketingIdeia;
use App\Models\MarketingMetrica;
use App\Models\User;
use Database\Seeders\MarketingSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Deixa a base pronta logo depois do `migrate`, sem ninguém olhando.
 *
 * Roda a cada inicialização do contêiner, então tudo aqui precisa ser seguro
 * de repetir: semear só acontece com a base vazia, e o acesso inicial só é
 * gravado quando falta ou quando a senha do ambiente mudou.
 */
class PrepararBase extends Command
{
    protected $signature = 'hc:preparar';

    protected $description = 'Semeia a base vazia e garante o acesso inicial do ambiente';

    public function handle(): int
    {
        if (User::count() === 0) {
            $this->components->info('Base vazia: semeando os dados iniciais.');
            $this->call('db:seed', ['--force' => true]);
        } elseif ($this->precisaSemeiarMarketing()) {
            // Base já em uso (ex.: Render free sem Shell): completa/atualiza o rascunho Marketing.
            $this->components->info('Marketing: atualizando métricas, conteúdos e ideias de rascunho.');
            $this->call('db:seed', ['--class' => MarketingSeeder::class, '--force' => true]);
        }

        // Idempotente: garante links do Academy mesmo em bases já semeadas.
        $this->call('hc:sincronizar-academy');

        return $this->acessoInicial();
    }

    private function precisaSemeiarMarketing(): bool
    {
        if (! Schema::hasTable('marketing_metricas')) {
            return false;
        }

        // Com API configurada, métricas/conteúdos vêm da sync — o seeder só completa ideias.
        if ($this->marketingApisConfiguradas()) {
            return MarketingIdeia::query()->count() === 0;
        }

        if (MarketingMetrica::query()->count() === 0) {
            return true;
        }

        // Deploy local/demo: métricas antigas existem, mas faltam conteúdos.
        if (Schema::hasTable('marketing_conteudos') && MarketingConteudo::query()->count() === 0) {
            return true;
        }

        return MarketingIdeia::query()->count() === 0;
    }

    private function marketingApisConfiguradas(): bool
    {
        $youtube = (string) config('hc.marketing.youtube_api_key') !== ''
            && (string) config('hc.marketing.youtube_channel_id') !== '';
        $instagram = (string) config('hc.marketing.instagram_access_token') !== ''
            && (string) config('hc.marketing.instagram_user_id') !== '';

        return $youtube || $instagram;
    }

    private function acessoInicial(): int
    {
        $email = config('hc.acesso_inicial.email');
        $senha = config('hc.acesso_inicial.senha');

        if (! $email || ! $senha) {
            return self::SUCCESS;
        }

        if (mb_strlen($senha) < 8) {
            $this->components->error('HC_ACESSO_SENHA precisa de ao menos 8 caracteres: o acesso inicial não foi criado.');

            return self::FAILURE;
        }

        $usuario = User::firstOrNew(['email' => $email]);

        if ($usuario->exists && Hash::check($senha, $usuario->password)) {
            return self::SUCCESS;
        }

        $criando = ! $usuario->exists;

        // Nome, perfil e área só na criação: quem já entrou pode ter ajustado
        // o próprio cadastro pela tela de Usuários, e um restart não é motivo
        // para desfazer isso. A senha é a única coisa que o ambiente mantém.
        if ($criando) {
            $usuario->fill([
                'name' => config('hc.acesso_inicial.nome'),
                'perfil' => 'Administrador',
                'area' => 'Gestão',
                'status' => 'Ativo',
            ]);
        }

        $usuario->password = $senha;
        $usuario->save();

        $this->components->info(
            ($criando ? 'Acesso inicial criado' : 'Senha do acesso inicial atualizada')." para {$email}."
        );

        return self::SUCCESS;
    }
}
