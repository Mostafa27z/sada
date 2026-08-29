<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'format' => $this->format,
            'parameters' => $this->parameters,
            'status' => $this->status,
            'file_path' => $this->file_path ? url('storage/' . $this->file_path) : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
