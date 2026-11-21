<?php

namespace App\Http\Resources\Enquiry;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the person who sent the form gets back: a reference to quote, nothing
 * else. Built from an array so a honeypot answer has exactly the same shape.
 */
class EnquiryReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->resource['reference'],
            'type' => $this->resource['type'],
        ];
    }
}
