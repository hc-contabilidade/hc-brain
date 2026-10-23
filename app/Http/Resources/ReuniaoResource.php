<?php

namespace App\Http\Resources;

use App\Models\Reuniao;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Reuniao */
class ReuniaoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'empresa' => $this->nomeDaEmpresa(),
            'empresaId' => $this->empresa_id,
            // O modal da reunião mostra o sócio da empresa, então ele vem junto.
            'socio' => $this->whenLoaded('empresa', fn () => $this->empresa?->relationLoaded('socio')
                ? "{$this->empresa->socio->nome} ({$this->empresa->socio->cargo})"
                : null),
            'tipo' => $this->tipo,
            'data' => $this->data->format('d/m/Y'),
            // A agenda ordena e agrupa por esta chave, então ela vem pronta.
            'dataOrd' => $this->data->format('Y-m-d'),
            'horario' => $this->horario,
            // Mesmo rótulo da tag da agenda (Equipe HC, Rafael…), não o nome cru do Google.
            'responsavel' => $this->contaGoogle !== null
                ? $this->contaGoogle->rotuloNaAgenda()
                : $this->responsavel,
            'participantes' => $this->participantes,
            'resumo' => $this->resumo,
            'decisoes' => $this->decisoes,
            'proximosPassos' => $this->proximos_passos,
            'status' => $this->status,
            'linkMeet' => $this->link_meet,
            'cor' => $this->cor,
            // Qual conta Google trouxe a reunião (Rafa, comercial, …).
            'agenda' => $this->contaGoogle === null ? null : [
                'nome' => $this->contaGoogle->rotuloNaAgenda(),
                'email' => $this->contaGoogle->email,
            ],
        ];
    }
}
