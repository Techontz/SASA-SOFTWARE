<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Domain\Billing\PlanService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\PlanResource;
use App\Support\ApiResponse;

/** The public pricing page. Archived plans are not offered. */
class PublicPlanController extends Controller
{
    public function __construct(private readonly PlanService $plans) {}

    public function index()
    {
        return ApiResponse::data(PlanResource::collection($this->plans->list()));
    }
}
