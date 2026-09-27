<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Search\SearchService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(private readonly SearchService $search) {}

    public function __invoke(Request $request)
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'limit' => ['nullable', 'integer', 'between:1,20'],
        ]);

        return ApiResponse::data($this->search->search(
            $this->project()->id,
            $request->input('q'),
            (int) $request->input('limit', 5),
        ));
    }
}
