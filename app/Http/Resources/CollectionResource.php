<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CollectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'color' => $this->color,
            'articles_count' => $this->whenCounted('articles'),
            'articles' => ArticleResource::collection($this->whenLoaded('articles')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
