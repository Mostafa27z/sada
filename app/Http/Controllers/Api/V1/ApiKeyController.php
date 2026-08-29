<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\StoreApiKeyRequest;
use App\Http\Resources\ApiKeyResource;
use App\Models\ApiKey;
use App\Support\ApiResponse;
use Illuminate\Support\Str;

class ApiKeyController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $keys = ApiKey::latest()->get();

        return $this->success(ApiKeyResource::collection($keys));
    }

    public function store(StoreApiKeyRequest $request)
    {
        $plainKey = 'sada_live_' . Str::random(32);
        $keyHash = hash('sha256', $plainKey);
        $prefix = substr($plainKey, 0, 14) . '...';

        $apiKey = ApiKey::create([
            'name' => $request->validated()['name'],
            'key_hash' => $keyHash,
            'key_prefix' => $prefix,
            'expires_at' => $request->validated()['expires_at'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $apiKey->plain_key = $plainKey;

        return $this->created(new ApiKeyResource($apiKey));
    }

    public function destroy(int $id)
    {
        $key = ApiKey::find($id);

        if (!$key) {
            return $this->error(__('messages.not_found'), 404);
        }

        $key->delete();

        return $this->success(null, __('messages.deleted'));
    }
}
