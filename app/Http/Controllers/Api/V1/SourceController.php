<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\StoreSourceRequest;
use App\Http\Resources\SourceResource;
use App\Models\Source;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class SourceController extends Controller
{
    use ApiResponse;

    /**
     * List accessible sources (Global + Active Tenant Private Sources).
     */
    public function index(Request $request)
    {
        $query = Source::accessible();

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }

        $sources = $query->latest()->paginate($request->get('per_page', 20));

        return $this->paginated($sources, SourceResource::class);
    }

    /**
     * Store a new private source for current active tenant.
     */
    public function store(StoreSourceRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;

        $source = Source::create($data);

        return $this->created(new SourceResource($source));
    }

    /**
     * Get specific source details.
     */
    public function show(int $id)
    {
        $source = Source::accessible()->where('id', $id)->first();

        if (!$source) {
            return $this->error(__('messages.not_found'), 404);
        }

        return $this->success(new SourceResource($source));
    }

    /**
     * Update private source.
     */
    public function update(StoreSourceRequest $request, int $id)
    {
        $source = Source::accessible()->where('id', $id)->whereNotNull('tenant_id')->first();

        if (!$source) {
            return $this->error(__('messages.not_found'), 404);
        }

        $source->update($request->validated());

        return $this->success(new SourceResource($source->fresh()), __('messages.updated'));
    }

    /**
     * Soft delete private source.
     */
    public function destroy(int $id)
    {
        $source = Source::accessible()->where('id', $id)->whereNotNull('tenant_id')->first();

        if (!$source) {
            return $this->error(__('messages.not_found'), 404);
        }

        $source->delete();

        return $this->success(null, __('messages.deleted'));
    }
}
