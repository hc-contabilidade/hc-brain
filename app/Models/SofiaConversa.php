<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id', 'user_id', 'titulo', 'modo'])]
class SofiaConversa extends Model
{
    use HasUuids;

    protected $table = 'sofia_conversas';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(SofiaMensagem::class, 'conversa_id')->orderBy('id');
    }

    /**
     * O mesmo formato que a tela já guarda: id, título, instante em ms e as
     * mensagens na ordem em que foram ditas.
     *
     * @return array{id: string, titulo: string, atualizadoEm: int, mensagens: list<array{autor: string, texto: string, voz?: true}>}
     */
    public function paraTela(): array
    {
        return [
            'id' => $this->id,
            'titulo' => $this->titulo,
            'atualizadoEm' => $this->updated_at === null
                ? (int) floor(microtime(true) * 1000)
                : $this->updated_at->getTimestamp() * 1000,
            'mensagens' => $this->mensagens
                ->map(function (SofiaMensagem $mensagem): array {
                    $item = [
                        'autor' => $mensagem->autor,
                        'texto' => $mensagem->texto,
                    ];

                    if ($mensagem->voz) {
                        $item['voz'] = true;
                    }

                    return $item;
                })
                ->values()
                ->all(),
        ];
    }
}
