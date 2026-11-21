<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Domain\Billing\PlanService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\PlanRequest;
use App\Http\Resources\Billing\AdminPlanResource;
use App\Models\Plan;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** Plans, for whoever holds billing.manage. Platform wide: no project context. */
class AdminPlanController extends Controller
{
    public function __construct(private readonly PlanService $plans) {}

    public function index(Request $request)
    {
        return ApiResponse::data(AdminPlanResource::collection($this->plans->list($request->boolean('include_archived'))));
    }

    public function store(PlanRequest $request)
    {
        return ApiResponse::data(new AdminPlanResource($this->plans->create($request->validated())), [], 201);
    }

    public function update(PlanRequest $request, Plan $plan)
    {
        return ApiResponse::data(new AdminPlanResource($this->plans->update($plan, $request->validated())));
    }

    /** "Delete" archives: the plan stops being offered, nothing is lost. */
    public function destroy(Plan $plan)
    {
        return ApiResponse::data(new AdminPlanResource($this->plans->archive($plan)), [
            'message' => "{$plan->name} has been archived. It is no longer offered, and requests made against it are kept.",
        ]);
    }

    public function restore(Plan $plan)
    {
        return ApiResponse::data(new AdminPlanResource($this->plans->restore($plan)));
    }
}
