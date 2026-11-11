<?php

namespace App\Http\Resources;

use App\Models\Empresa;
use App\Models\Fonte;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A empresa como a lista de clientes precisa dela: cadastro, sócio e os totais
 * de reuniões e fontes, que aparecem no cartão sem exigir uma segunda chamada.
 * A lista das fontes só vai quando foi carregada — no detalhe e no cadastro —,
 * porque a carteira inteira com todas as fontes pesa sem ninguém olhar.
 *
 * @mixin Empresa
 */
class EmpresaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'setor' => $this->setor,
            'documento' => $this->documento,
            'documentoTipo' => $this->documento_tipo,
            'status' => $this->status,
            'statusTag' => $this->status_tag,
            'omieAppId' => $this->omie_app_id,
            'omieAppNome' => $this->whenLoaded('omieApp', fn () => $this->omieApp?->nome),
            'omieCodigoCliente' => $this->omie_codigo_cliente,
            'socio' => [
                'nome' => $this->socio->nome,
                'cargo' => $this->socio->cargo,
                'email' => $this->socio->email,
                'telefone' => $this->socio->telefone,
                'participacao' => $this->socio->participacao,
                'desde' => $this->socio->desde,
            ],
            'fontes' => $this->whenLoaded('fontes', fn () => $this->fontes->map(fn (Fonte $fonte) => [
                'tipo' => $fonte->tipo,
                'nome' => $fonte->nome,
                'info' => $fonte->info,
            ])->all()),
            'totalFontes' => $this->fontes_count ?? $this->fontes()->count(),
            'totalReunioes' => $this->reunioes_count ?? $this->reunioes()->count(),
            'ultimaReuniao' => $this->whenLoaded('ultimaReuniao', fn () => $this->ultimaReuniao === null ? null : [
                'tipo' => $this->ultimaReuniao->tipo,
                'data' => $this->ultimaReuniao->data->format('d/m/Y'),
            ]),
        ];
    }
}
