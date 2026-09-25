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

        $complaint = Complaint::create([
            'tenant_id' => $validated['tenant_id'],
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'opinion' => $validated['opinion'],
            'rate' => $validated['rate'],
            'status' => 'pending',
        ]);

        \App\Jobs\ProcessComplaintAiJob::dispatch($complaint);

        return $this->created($complaint, 'Complaint submitted successfully');
    }
}
