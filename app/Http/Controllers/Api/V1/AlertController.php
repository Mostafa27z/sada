<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = Alert::query();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        $alerts = $query->latest()->paginate($request->get('per_page', 20));

        return $this->paginated($alerts, AlertResource::class);
    }

    public function markAsRead(int $id)
    {
        $alert = Alert::find($id);

        if (!$alert) {
            return $this->error(__('messages.not_found'), 404);
        }

        $alert->update([
            'status' => Alert::STATUS_READ,
            'read_at' => now(),
        ]);

        return $this->success(new AlertResource($alert));
    }

    public function resolve(int $id)
    {
        $alert = Alert::find($id);

        if (!$alert) {
            return $this->error(__('messages.not_found'), 404);
        }

        $alert->update([
            'status' => Alert::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);

        return $this->success(new AlertResource($alert));
    }
}
