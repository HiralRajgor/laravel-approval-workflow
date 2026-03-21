<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'author' => new UserResource($this->whenLoaded('author')),
            'latest_transition' => new DocumentTransitionResource($this->whenLoaded('latestTransition')),
            'transitions' => DocumentTransitionResource::collection($this->whenLoaded('transitions')),
            'allowed_transitions' => array_map(
                fn ($s) => ['value' => $s->value, 'label' => $s->label()],
                $this->status->allowedTransitions(),
            ),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
