<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreComplaintRequest;
use App\Models\Complaint;
use App\Support\ApiResponse;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PublicComplaintController extends Controller
{
    use ApiResponse;

    /**
     * Generate a QR Code for a target redirection URL.
     *
     * @param Request $request
     * @return Response|JsonResponse
     */
    public function generateQrCode(Request $request): Response|JsonResponse
    {
        $request->validate([
            'url' => ['required', 'url'],
            'size' => ['nullable', 'integer', 'min:50', 'max:1000'],
            'format' => ['nullable', 'string', 'in:svg,json,base64'],
        ]);

        $url = $request->input('url');
        $size = (int) $request->input('size', 300);
        $format = $request->input('format', 'svg');

        $renderer = new ImageRenderer(
            new RendererStyle($size),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);
        $svgContent = $writer->writeString($url);

        if ($format === 'json' || $format === 'base64') {
            $base64 = 'data:image/svg+xml;base64,' . base64_encode($svgContent);
            return $this->success([
                'url' => $url,
                'qr_code' => $base64,
            ], 'QR Code generated successfully');
        }

        return response($svgContent, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-cache, private',
        ]);
    }

    /**
     * Submit a customer complaint / feedback.
     *
     * @param StoreComplaintRequest $request
     * @return JsonResponse
     */
    public function store(StoreComplaintRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $locationId = $validated['tenant_location_id'] ?? $validated['location_id'] ?? null;

        $complaint = Complaint::create([
            'tenant_id' => $validated['tenant_id'],
            'tenant_location_id' => $locationId,
            'name' => $validated['name'] ?? null,
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'opinion' => $validated['opinion'],
            'rate' => $validated['rate'],
            'stage' => 'new',
            'status' => 'new',
        ]);

        $complaint->activities()->create([
            'tenant_id' => $complaint->tenant_id,
            'type' => 'status_change',
            'comment' => 'تم استلام الشكوى بنجاح وهي قيد الإدراج للمراجعة من قبل فريق خدمة العملاء.',
            'is_visible_to_customer' => true,
        ]);

        \App\Jobs\ProcessComplaintAiJob::dispatch($complaint);

        return $this->created($complaint, 'Complaint submitted successfully');
    }

    /**
     * Track a complaint publicly by tracking number (or ID) and view customer-visible notes/updates.
     *
     * @param string $trackingNumber
     * @return JsonResponse
     */
    public function track(string $trackingNumber): JsonResponse
    {
        $complaint = Complaint::withoutGlobalScopes()
            ->with(['tenant', 'location'])
            ->where(function ($query) use ($trackingNumber) {
                $query->where('tracking_number', $trackingNumber);
                if (is_numeric($trackingNumber)) {
                    $query->orWhere('id', (int) $trackingNumber);
                }
            })
            ->first();

        if (!$complaint) {
            return $this->error('لم يتم العثور على أي شكوى برقم المتابعة المدخل. يرجى التأكد من الرقم والمحاولة مرة أخرى.', 404);
        }

        $activities = \App\Models\ComplaintActivity::withoutGlobalScopes()
            ->where('complaint_id', $complaint->id)
            ->where('is_visible_to_customer', true)
            ->latest()
            ->get()
            ->map(function ($activity) {
                return [
                    'id' => $activity->id,
                    'type' => $activity->type,
                    'comment' => $activity->comment,
                    'created_at' => $activity->created_at?->toISOString(),
                ];
            });

        return $this->success([
            'id' => $complaint->id,
            'tracking_number' => $complaint->tracking_number,
            'stage' => $complaint->stage ?? $complaint->status,
            'status' => $complaint->stage ?? $complaint->status,
            'name' => $complaint->name,
            'phone' => $complaint->phone ? (substr($complaint->phone, 0, 4) . '****' . substr($complaint->phone, -2)) : null,
            'opinion' => $complaint->opinion,
            'rate' => $complaint->rate,
            'created_at' => $complaint->created_at?->toISOString(),
            'updated_at' => $complaint->updated_at?->toISOString(),
            'resolved_at' => $complaint->resolved_at?->toISOString(),
            'tenant' => $complaint->tenant ? [
                'id' => $complaint->tenant->id,
                'name' => $complaint->tenant->name,
                'logo' => $complaint->tenant->logo,
            ] : null,
            'location' => $complaint->location ? [
                'id' => $complaint->location->id,
                'business_name' => $complaint->location->business_name,
                'address' => $complaint->location->address,
            ] : null,
            'activities' => $activities,
        ], 'Complaint tracking details retrieved successfully');
    }

    /**
     * Get public tenant details (name, logo, theme colors, branches) for public forms.
     */
    public function getTenantInfo(Request $request): JsonResponse
    {
        $tenantId = $request->query('tenant_id', 1);
        $tenant = \App\Models\Tenant::find($tenantId);

        if (!$tenant) {
            return $this->notFound('الجهة غير موجودة');
        }

        $branches = \App\Models\TenantLocation::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->get(['id', 'business_name', 'address', 'maps_url'])
            ->map(fn ($b) => [
                'id' => $b->id,
                'business_name' => $b->business_name,
                'address' => $b->address,
                'has_google_location' => !empty($b->maps_url),
            ]);

        return $this->success([
            'id' => $tenant->id,
            'name' => $tenant->name,
            'logo' => $tenant->logo,
            'theme' => $tenant->settings['theme'] ?? null,
            'branches' => $branches,
        ], 'Tenant info retrieved successfully');
    }
}
