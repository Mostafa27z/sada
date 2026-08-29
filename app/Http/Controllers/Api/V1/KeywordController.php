<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\StoreKeywordRequest;
use App\Http\Requests\Monitoring\UpdateKeywordRequest;
use App\Http\Resources\KeywordResource;
use App\Models\Keyword;
use App\Support\ApiResponse;

class KeywordController extends Controller
{
    use ApiResponse;

    /**
     * List tenant keywords with search, filter, and pagination.
     */
    public function index()
    {
        $query = Keyword::query();

        if (request()->has('status')) {
            $query->where('status', request('status'));
        }

        if (request()->has('priority')) {
            $query->where('priority', request('priority'));
        }

        if (request()->has('search')) {
            $search = request('search');
            $query->where('name', 'like', "%{$search}%");
        }

        $keywords = $query->latest()->paginate(request('per_page', 20));

        return $this->paginated($keywords, KeywordResource::class);
    }

    /**
     * Store new keyword in current active tenant.
     */
    public function store(StoreKeywordRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;

        $keyword = Keyword::create($data);

        return $this->created(new KeywordResource($keyword));
    }

    /**
     * Get specific keyword details.
     */
    public function show(int $id)
    {
        $keyword = Keyword::find($id);

        if (!$keyword) {
            return $this->error(__('messages.not_found'), 404);
        }

        return $this->success(new KeywordResource($keyword));
    }

    /**
     * Update keyword.
     */
    public function update(UpdateKeywordRequest $request, int $id)
    {
        $keyword = Keyword::find($id);

        if (!$keyword) {
            return $this->error(__('messages.not_found'), 404);
        }

        $keyword->update($request->validated());

        return $this->success(new KeywordResource($keyword->fresh()), __('messages.updated'));
    }

    /**
     * Soft delete keyword.
     */
    public function destroy(int $id)
    {
        $keyword = Keyword::find($id);

        if (!$keyword) {
            return $this->error(__('messages.not_found'), 404);
        }

        $keyword->delete();

        return $this->success(null, __('messages.deleted'));
    }

    public function activate(int $id)
    {
        $keyword = Keyword::find($id);

        if (!$keyword) {
            return $this->error(__('messages.not_found'), 404);
        }

        $keyword->update(['status' => Keyword::STATUS_ACTIVE]);
        return $this->success(new KeywordResource($keyword));
    }

    public function pause(int $id)
    {
        $keyword = Keyword::find($id);

        if (!$keyword) {
            return $this->error(__('messages.not_found'), 404);
        }

        $keyword->update(['status' => Keyword::STATUS_PAUSED]);
        return $this->success(new KeywordResource($keyword));
    }

    public function archive(int $id)
    {
        $keyword = Keyword::find($id);

        if (!$keyword) {
            return $this->error(__('messages.not_found'), 404);
        }

        $keyword->update(['status' => Keyword::STATUS_ARCHIVED]);
        return $this->success(new KeywordResource($keyword));
    }
}
