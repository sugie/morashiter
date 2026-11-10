<?php

namespace App\Services\DemoShop;

class Catalog
{
    public function data(): array
    {
        return json_decode(file_get_contents(resource_path('data/demo-shop/catalog.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function product(): array
    {
        return $this->data()['product'];
    }

    public function notices(): array
    {
        return $this->data()['notices'];
    }
}
