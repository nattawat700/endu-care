<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['sku', 'name', 'kind', 'tracking', 'category', 'brand', 'unit', 'retail_satang', 'dealer_satang', 'partner_satang', 'cost_satang', 'image', 'version'])]
#[Hidden(['cost_satang', 'dealer_satang', 'partner_satang', 'image'])]
class CatalogProduct extends Model
{
    public const KINDS = ['part' => 'อะไหล่', 'phone' => 'โทรศัพท์', 'accessory' => 'อุปกรณ์', 'labor' => 'ค่าแรง', 'service' => 'บริการ'];

    public const TRACKING = ['none' => 'ไม่มีสต๊อก', 'quantity' => 'นับจำนวน', 'serialized' => 'รายเครื่อง'];

    public function barcodes(): HasMany
    {
        return $this->hasMany(CatalogBarcode::class);
    }

    public function compatibilities(): HasMany
    {
        return $this->hasMany(CatalogCompatibility::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(CatalogBalance::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(CatalogUnit::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CatalogMovement::class);
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'retail_satang' => 'integer', 'dealer_satang' => 'integer', 'partner_satang' => 'integer', 'cost_satang' => 'integer'];
    }
}
