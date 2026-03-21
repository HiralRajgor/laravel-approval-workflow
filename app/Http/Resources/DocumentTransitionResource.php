<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DocumentTransition */
class DocumentTransitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'from_status' => [
                'value' => $this->from_status->value,
                'label' => $this->from_status->label(),
                'color' => $this->from_status->color(),
            ],
            'to_status' => [
                'value' => $this->to_status->value,
                'label' => $this->to_status->label(),
                'color' => $this->to_status->color(),
            ],
            'actor'      => new UserResource($this->whenLoaded('actor')),
            'comment'    => $this->comment,
            'summary'    => $this->summary(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
