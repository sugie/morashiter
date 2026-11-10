<?php

namespace App\Http\Controllers\DemoShop;

use App\Http\Controllers\Controller;
use App\Services\DemoShop\Catalog;
use Illuminate\Contracts\View\View;

class StoreController extends Controller
{
    public function index(Catalog $catalog): View
    {
        return view('demo-shop.store', ['product' => $catalog->product(), 'notices' => $catalog->notices(),
            'clientId' => (string) config('demo_shop.paypal.client_id', '')]);
    }

    public function show(string $notice, Catalog $catalog): View
    {
        $record = collect($catalog->notices())->firstWhere('id', $notice);
        abort_unless($record, 404);

        return view('demo-shop.notice', ['notice' => $record]);
    }
}
