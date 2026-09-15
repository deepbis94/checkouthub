<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'offer_id' => ['required', 'integer'],
            'upsell_ids' => ['sometimes', 'array'],
            'upsell_ids.*' => ['integer'],
            'discount_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'customer_email' => ['sometimes', 'nullable', 'email'],
            'expires_in' => ['sometimes', 'integer', 'min:60', 'max:86400'],
            'proration_days_remaining' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'proration_period_days' => ['sometimes', 'integer', 'min:1', 'max:366'],
        ];
    }
}
