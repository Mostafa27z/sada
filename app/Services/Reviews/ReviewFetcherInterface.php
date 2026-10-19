<?php

namespace App\Services\Reviews;

use App\DTOs\NormalizedReview;
use App\Models\TenantLocation;

interface ReviewFetcherInterface
{
    /**
     * Fetch and normalize reviews for a given tenant location.
     *
     * @param TenantLocation $location
     * @param int $maxReviews
     * @return NormalizedReview[]
     */
    public function fetchReviews(TenantLocation $location, int $maxReviews = 50): array;
}
