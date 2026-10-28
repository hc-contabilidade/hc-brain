<?php

/*
|--------------------------------------------------------------------------
| HC Brain
|--------------------------------------------------------------------------
| As decisões do produto que mudam entre ambientes. O que é regra de negócio
| fica no código; o que depende de onde o sistema está rodando mora aqui.
*/

return [

    /*
    | Senha da equipe criada pelo `UsuarioSeeder`. O padrão é o que a própria
    | tela de login anuncia no rodapé de protótipo demonstrativo: a base
    | semeada serve para mostrar o sistema, e esconder a senha dela só
    | atrapalharia. Num ambiente que deixe de ser demonstração, defina a
    | variável — ou crie o acesso pelo `hc:acesso` e apague a equipe semeada.
    */
    'senha_semeada' => env('HC_SENHA_SEMEADA') ?: '123456',

    /*
    | O acesso que abre um ambiente recém-publicado. Lá o seeder dá senhas
    | aleatórias de propósito, e num servidor sem terminal — o Render é um —
    | o ambiente é o único caminho para a primeira entrada. Enquanto estas
    | variáveis existirem, elas mandam na senha desse acesso: trocá-las é
    | como se recupera a entrada quando ninguém mais consegue entrar.
    */
    'acesso_inicial' => [
        'email' => env('HC_ACESSO_EMAIL'),
        'senha' => env('HC_ACESSO_SENHA'),
        'nome' => env('HC_ACESSO_NOME', 'Administrador'),
    ],

    'documentos' => [

        /*
        | Onde o arquivo enviado é guardado. Em produção com mais de uma
        | instância isto precisa ser um disco compartilhado (s3), senão cada
        | servidor enxerga só o que ele mesmo recebeu.
        */
        'disco' => env('HC_DISCO_DOCUMENTOS', 'local'),

        /* Teto por arquivo, em kilobytes. */
        'tamanho_maximo' => (int) env('HC_TAMANHO_MAXIMO_DOCUMENTO', 20480),

        /*
        | Extensões aceitas no envio. A lista é curta de propósito: documento
        | é o que a HC arquiva, não qualquer binário que alguém suba.
        */
        'extensoes' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt', 'png', 'jpg', 'jpeg'],

    ],

    /*
    | A Sofia conversa por Chat Completions (OpenAI ou Gemini no formato
    | compatível) e transcreve áudio com a chave OpenAI. Sem nenhuma chave
    | de chat, a tela e a API avisam. O navegador que reconhece voz
    | transcreve sozinho; arquivo anexado passa pela transcrição daqui.
    */
    'sofia' => [
        'chave' => env('OPENAI_API_KEY'),

        /* Teto por áudio, em kilobytes. */
        'audio_kb' => 10240,

        'extensoes' => ['webm', 'mp3', 'mpeg', 'mpga', 'm4a', 'mp4', 'wav', 'ogg', 'oga'],

        'transcricao' => [
            'url' => env('OPENAI_TRANSCRIPTION_URL', 'https://api.openai.com/v1/audio/transcriptions'),
            'modelo' => env('OPENAI_TRANSCRIPTION_MODEL', 'whisper-1'),
        ],

        'chat' => [
            /* Id do catálogo abaixo. Continua aceitando OPENAI_CHAT_MODEL. */
            'modelo' => env('SOFIA_CHAT_MODEL', env('OPENAI_CHAT_MODEL', 'gpt-4o-mini')),
        ],

        'provedores' => [
            'openai' => [
                'chave' => env('OPENAI_API_KEY'),
                'url' => env('OPENAI_CHAT_URL', 'https://api.openai.com/v1/chat/completions'),
            ],
            'gemini' => [
                'chave' => env('GEMINI_API_KEY'),
                'url' => env(
                    'GEMINI_CHAT_URL',
                    'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
                ),
            ],
        ],

        /*
        | Opções do seletor na tela. `api` é o nome que o provedor espera;
        | só entram na lista se a chave do provedor estiver no ambiente.
        */
        'modelos' => [
            'gpt-4o-mini' => [
                'rotulo' => 'ChatGPT',
                'subtitulo' => 'Rápido',
                'provedor' => 'openai',
                'api' => 'gpt-4o-mini',
            ],
            'gpt-4o' => [
                'rotulo' => 'ChatGPT',
                'subtitulo' => 'Avançado',
                'provedor' => 'openai',
                'api' => 'gpt-4o',
            ],
            'gemini-2.0-flash' => [
                'rotulo' => 'Gemini',
                'subtitulo' => 'Rápido',
                'provedor' => 'gemini',
                'api' => 'gemini-2.0-flash',
            ],
            'gemini-2.5-pro' => [
                'rotulo' => 'Gemini',
                'subtitulo' => 'Avançado',
                'provedor' => 'gemini',
                'api' => 'gemini-2.5-pro',
            ],
        ],
    ],

];
