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

    public function index(\Illuminate\Http\Request $request)
    {
        // Global tenant stats across ALL campaigns (not limited by current page)
        $baseStats = Collection::query();
        $totalAll = (clone $baseStats)->count();
        $activeAll = (clone $baseStats)->where(function ($q) {
            $q->where('status', Collection::STATUS_ACTIVE)
              ->orWhere('status', Collection::STATUS_PENDING);
        })->count();
        $completedAll = (clone $baseStats)->where('status', Collection::STATUS_COMPLETED)->count();
        $scheduledAll = (clone $baseStats)->where('status', 'scheduled')->count();

        $extraMeta = [
            'stats' => [
                'total' => $totalAll,
                'active' => $activeAll,
                'completed' => $completedAll,
                'scheduled' => $scheduledAll,
            ],
        ];

        $query = Collection::with(['articles.comments'])->withCount('articles')->latest();

        // 1. Search across name, description, link, and platform
        if ($request->filled('search')) {
            $s = trim((string) $request->search);
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('description', 'like', "%{$s}%")
                  ->orWhere('link', 'like', "%{$s}%")
                  ->orWhere('platform', 'like', "%{$s}%");
            });
        }

        // 2. Search Method Filter (type: trend vs keyword vs direct link/campaign)
        if ($request->filled('type') && $request->type !== 'all') {
            if ($request->type === 'trend') {
                $query->where(function ($q) {
                    $q->where('description', 'like', 'رصد تريند:%')
                      ->orWhere('name', 'like', 'تريند:%');
                });
            } elseif ($request->type === 'keyword') {
                $query->where(function ($q) {
                    $q->where(function ($sub) {
                        $sub->whereNull('link')
                          ->orWhere('link', '')
                          ->orWhere('link', '#')
                          ->orWhere('name', 'like', 'رصد كلمة:%')
                          ->orWhere('name', 'like', 'كلمة:%');
                    })
                    ->where('description', 'not like', 'رصد تريند:%')
                    ->where('name', 'not like', 'تريند:%');
                });
            } elseif ($request->type === 'campaign') {
                $query->where(function ($q) {
                    $q->whereNotNull('link')
                      ->where('link', '!=', '')
                      ->where('link', '!=', '#')
                      ->where('name', 'not like', 'رصد كلمة:%')
                      ->where('name', 'not like', 'كلمة:%')
                      ->where('description', 'not like', 'رصد تريند:%')
                      ->where('name', 'not like', 'تريند:%');
                });
            }
        }

        // 3. Status Filter (status: active, completed, scheduled, pending)
        if ($request->filled('status') && $request->status !== 'all') {
            $status = $request->status;
            if ($status === 'active') {
                $query->where(function ($q) {
                    $q->where('status', Collection::STATUS_ACTIVE)
                      ->orWhere('status', Collection::STATUS_PENDING);
                });
            } elseif ($status === 'completed') {
                $query->where('status', Collection::STATUS_COMPLETED);
            } elseif ($status === 'scheduled') {
                $query->where('status', 'scheduled');
            } elseif ($status === 'pending') {
                $query->where('status', Collection::STATUS_PENDING);
            } else {
                $query->where('status', $status);
            }
        }

        $perPage = (int) $request->get('per_page', 5);
        if ($perPage > 0) {
            $collections = $query->paginate($perPage);
            return $this->paginated($collections, CollectionResource::class, $extraMeta);
        }

        $collections = $query->get();
        return $this->success(CollectionResource::collection($collections));
    }

    public function store(StoreCollectionRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;

        if (array_key_exists('keywords', $data)) {
            if (is_array($data['keywords'])) {
                $cleaned = array_values(array_filter(array_map('trim', $data['keywords'])));
                $data['keywords'] = !empty($cleaned) ? implode(', ', $cleaned) : null;
            } elseif (is_string($data['keywords'])) {
                $data['keywords'] = trim($data['keywords']) ?: null;
            } else {
                $data['keywords'] = null;
            }
        } elseif (!empty($data['keyword']) && is_string($data['keyword'])) {
            $data['keywords'] = trim($data['keyword']) ?: null;
        } else {
            $data['keywords'] = null;
        }
        unset($data['keyword']);

        if (!empty($data['platforms']) && is_array($data['platforms'])) {
            $data['platform'] = implode(',', array_filter(array_map('trim', $data['platforms'])));
        }
        unset($data['platforms']);

        $data['status'] = Collection::STATUS_PENDING;
        $collection = Collection::create($data);

        $commentsLimit = $data['comments_limit'] ?? 50;
        $this->syncLinkArticleAndComments($collection, $commentsLimit);
        $this->triggerKeywordScrapeIfApplicable($collection, $data);

        return $this->created(new CollectionResource($collection->fresh(['articles.comments'])));
    }

    public function show(string $id)
    {
        $collection = is_numeric($id)
            ? Collection::with(['articles.comments'])->find((int)$id)
            : Collection::with(['articles.comments'])->where('name', urldecode($id))->orWhere('name', $id)->first();

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

        $data = $request->validated();

        if (array_key_exists('keywords', $data)) {
            if (is_array($data['keywords'])) {
                $cleaned = array_values(array_filter(array_map('trim', $data['keywords'])));
                $data['keywords'] = !empty($cleaned) ? implode(', ', $cleaned) : null;
            } elseif (is_string($data['keywords'])) {
                $data['keywords'] = trim($data['keywords']) ?: null;
            } else {
                $data['keywords'] = null;
            }
        } elseif (!empty($data['keyword']) && is_string($data['keyword'])) {
            $data['keywords'] = trim($data['keyword']) ?: null;
        }
        unset($data['keyword']);

        if (!empty($data['platforms']) && is_array($data['platforms'])) {
            $data['platform'] = implode(',', array_filter(array_map('trim', $data['platforms'])));
        }
        unset($data['platforms']);

        if (!empty($data['link']) && empty($data['platform'])) {
            $url = strtolower($data['link']);
            if (str_contains($url, 'twitter.com') || str_contains($url, 'x.com')) {
                $data['platform'] = 'x';
            } elseif (str_contains($url, 'facebook.com') || str_contains($url, 'fb.watch')) {
                $data['platform'] = 'facebook';
            } elseif (str_contains($url, 'instagram.com')) {
                $data['platform'] = 'instagram';
            } elseif (str_contains($url, 'tiktok.com')) {
                $data['platform'] = 'tiktok';
            } else {
                $data['platform'] = 'web';
            }
        }

        $data['status'] = Collection::STATUS_PENDING;
        $data['error_message'] = null;
        $collection->update($data);

        $commentsLimit = $data['comments_limit'] ?? 50;
        $this->syncLinkArticleAndComments($collection, $commentsLimit);
        $this->triggerKeywordScrapeIfApplicable($collection, $data);

        return $this->success(new CollectionResource($collection->fresh(['articles.comments'])), __('messages.updated'));
    }

    protected function triggerKeywordScrapeIfApplicable(Collection $collection, array $data): void
    {
        $isKeyword = empty($collection->link) || $collection->link === '#' || !empty($data['keyword']) || !empty($data['keywords']) || str_starts_with($collection->name, 'رصد كلمة:') || str_starts_with($collection->name, 'كلمة:');
        if (!$isKeyword) {
            return;
        }

        $keywordsToScrape = [];
        if (!empty($data['keywords']) && is_array($data['keywords'])) {
            $keywordsToScrape = array_values(array_filter(array_map('trim', $data['keywords'])));
        } elseif (!empty($data['keywords']) && is_string($data['keywords'])) {
            $keywordsToScrape = array_values(array_filter(array_map('trim', explode(',', $data['keywords']))));
        } elseif (!empty($data['keyword'])) {
            $keywordsToScrape = array_values(array_filter(array_map('trim', explode(',', $data['keyword']))));
        } elseif (preg_match('/(?:رصد\s+)?كلمة:\s*"?([^"]+)"?/u', $collection->name, $matches)) {
            $keywordsToScrape = [trim($matches[1])];
        } elseif (preg_match('/(?:رصد\s+)?تريند:\s*"?([^"]+)"?/u', $collection->description, $tMatches)) {
            $keywordsToScrape = [trim($tMatches[1])];
        } elseif (preg_match('/(?:رصد\s+)?تريند:\s*"?([^"]+)"?/u', $collection->name, $tMatches)) {
            $keywordsToScrape = [trim($tMatches[1])];
        } elseif (!empty($collection->keywords)) {
            $keywordsToScrape = array_values(array_filter(array_map('trim', explode(',', $collection->keywords))));
        } elseif (empty($collection->link) || $collection->link === '#') {
            $cleanName = trim(preg_replace('/^(?:رصد\s+)?(?:تريند|كلمة):\s*/u', '', $collection->name));
            if (!empty($cleanName)) {
                $keywordsToScrape = [$cleanName];
            }
        }

        if (empty($keywordsToScrape)) {
            return;
        }

        if (empty($collection->keywords)) {
            $collection->update(['keywords' => implode(', ', $keywordsToScrape)]);
        }

        $rawPlats = !empty($data['platforms']) ? $data['platforms'] : explode(',', $collection->platform ?: 'x,facebook,instagram,web');
        $platforms = array_values(array_filter(array_map('trim', (array)$rawPlats)));

        \App\Jobs\ScrapeKeywordsJob::dispatch(
            $collection->tenant_id,
            $keywordsToScrape,
            !empty($platforms) ? $platforms : ['x', 'facebook', 'instagram', 'web'],
            $collection->country ?: 'SA',
            $collection->id
        );
    }

    protected function syncLinkArticleAndComments(Collection $collection, mixed $commentsLimit = 50): void
    {
        if (empty($collection->link)) {
            return;
        }

        $source = \App\Models\Source::firstOrCreate(
            ['tenant_id' => $collection->tenant_id, 'name' => ucfirst($collection->platform ?: 'web')],
            [
                'url' => 'https://' . ($collection->platform ?: 'web') . '.com',
                'platform' => $collection->platform ?: 'web',
                'country' => $collection->country ?: 'SA',
                'status' => 'active',
            ]
        );

        $article = \App\Models\Article::firstOrCreate(
            ['tenant_id' => $collection->tenant_id, 'url' => $collection->link],
            [
                'source_id' => $source->id,
                'title' => $collection->name,
                'content' => $collection->description ?: $collection->name,
                'summary' => $collection->name,
                'country' => $collection->country ?: 'SA',
                'sentiment' => 'positive',
                'published_at' => now(),
                'raw_data' => [
                    'platform' => $collection->platform ?: 'web',
                ],
            ]
        );

        if (!empty($collection->platform)) {
            $raw = $article->raw_data ?? [];
            if (($raw['platform'] ?? null) !== $collection->platform) {
                $raw['platform'] = $collection->platform;
                $article->update(['raw_data' => $raw]);
            }
        }

        $collection->articles()->syncWithoutDetaching([$article->id]);

        \App\Jobs\ScrapePostCommentsJob::dispatch($collection->tenant_id, [$collection->link], $article->id, $commentsLimit, $collection->id);
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

    public function sync(int $id)
    {
        $collection = Collection::find($id);

        if (!$collection) {
            return $this->error(__('messages.not_found'), 404);
        }

        $collection->update([
            'status' => Collection::STATUS_PENDING,
            'error_message' => null,
        ]);

        if (!empty($collection->link) && $collection->link !== '#') {
            $commentsLimit = $collection->comments_limit ?? 50;
            $this->syncLinkArticleAndComments($collection, $commentsLimit);
        } else {
            $this->triggerKeywordScrapeIfApplicable($collection, []);
        }

        return $this->success(
            new CollectionResource($collection->fresh(['articles.comments'])),
            'تم بدء المزامنة بنجاح وجارٍ سحب وتحليل أحدث البيانات والردود بالذكاء الاصطناعي.'
        );
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
