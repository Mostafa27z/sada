<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\StoreCollectionRequest;
use App\Http\Resources\CollectionResource;
use App\Models\Article;
use App\Models\Collection;
use App\Support\ApiResponse;

class CollectionController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $collections = Collection::withCount('articles')->latest()->get();

        return $this->success(CollectionResource::collection($collections));
    }

    public function store(StoreCollectionRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;

        $collection = Collection::create($data);

        return $this->created(new CollectionResource($collection));
    }

    public function show(int $id)
    {
        $collection = Collection::with('articles')->find($id);

        if (!$collection) {
            return $this->error(__('messages.not_found'), 404);
        }

        return $this->success(new CollectionResource($collection));
    }

    public function update(StoreCollectionRequest $request, int $id)
    {
        $collection = Collection::find($id);

        if (!$collection) {
            return $this->error(__('messages.not_found'), 404);
        }

        $collection->update($request->validated());

        return $this->success(new CollectionResource($collection->fresh()), __('messages.updated'));
    }

    public function destroy(int $id)
    {
        $collection = Collection::find($id);

        if (!$collection) {
            return $this->error(__('messages.not_found'), 404);
        }

        $collection->delete();

        return $this->success(null, __('messages.deleted'));
    }

    public function addArticle(int $id, int $articleId)
    {
        $collection = Collection::find($id);

        if (!$collection) {
            return $this->error(__('messages.not_found'), 404);
        }

        $article = Article::find($articleId);

        if (!$article) {
            return $this->error(__('messages.not_found'), 404);
        }

        if (!$collection->articles()->where('articles.id', $articleId)->exists()) {
            $collection->articles()->attach($articleId);
        }

        return $this->success(null, __('messages.article_added_to_collection'));
    }

    public function removeArticle(int $id, int $articleId)
    {
        $collection = Collection::find($id);

        if (!$collection) {
            return $this->error(__('messages.not_found'), 404);
        }

        $collection->articles()->detach($articleId);

        return $this->success(null, __('messages.article_removed_from_collection'));
    }
}
