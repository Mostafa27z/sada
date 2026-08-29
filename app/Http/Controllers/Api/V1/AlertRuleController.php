<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\StoreAlertRuleRequest;
use App\Http\Requests\Monitoring\UpdateAlertRuleRequest;
use App\Http\Resources\AlertRuleResource;
use App\Models\AlertRule;
use App\Support\ApiResponse;

class AlertRuleController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $rules = AlertRule::latest()->get();

        return $this->success(AlertRuleResource::collection($rules));
    }

    public function store(StoreAlertRuleRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;

        $rule = AlertRule::create($data);

        return $this->created(new AlertRuleResource($rule));
    }

    public function show(int $id)
    {
        $rule = AlertRule::find($id);

        if (!$rule) {
            return $this->error(__('messages.not_found'), 404);
        }

        return $this->success(new AlertRuleResource($rule));
    }

    public function update(UpdateAlertRuleRequest $request, int $id)
    {
        $rule = AlertRule::find($id);

        if (!$rule) {
            return $this->error(__('messages.not_found'), 404);
        }

        $rule->update($request->validated());

        return $this->success(new AlertRuleResource($rule->fresh()), __('messages.updated'));
    }

    public function destroy(int $id)
    {
        $rule = AlertRule::find($id);

        if (!$rule) {
            return $this->error(__('messages.not_found'), 404);
        }

        $rule->delete();

        return $this->success(null, __('messages.deleted'));
    }
}
