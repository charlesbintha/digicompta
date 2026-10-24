<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class Inventory
{
    public static function move(int $company, int $actor, int $product, int $quantity, string $source, string $reason, ?int $invoice = null): void
    {
        if (DB::table('stock_movements')->where('company_id', $company)->where('source_key', $source)->exists()) {
            return;
        }
        $item = DB::table('products')->where('company_id', $company)->where('id', $product)->lockForUpdate()->first();
        abort_unless($item && ! $item->archived && $item->type === 'product', 422, 'Produit de stock actif introuvable.');
        $available = (int) DB::table('stock_movements')->where('company_id', $company)->where('product_id', $product)->sum('quantity');
        abort_if($available + $quantity < 0, 422, 'Stock insuffisant pour '.$item->name.' : '.$available.' disponible(s).');
        DB::table('stock_movements')->insert(['company_id' => $company, 'created_by' => $actor, 'product_id' => $product, 'quantity' => $quantity, 'source_key' => $source, 'reason' => $reason, 'invoice_id' => $invoice, 'created_at' => now(), 'updated_at' => now()]);
    }

    public static function issue(object $invoice, int $actor): void
    {
        $items = DB::table('invoice_items')->join('products', 'products.id', '=', 'invoice_items.product_id')->where('invoice_id', $invoice->id)->where('products.company_id', $invoice->company_id)->where('products.type', 'product')->select('product_id')->selectRaw('SUM(quantity) as quantity')->groupBy('product_id')->orderBy('product_id')->get();
        foreach ($items as $item) {
            self::move($invoice->company_id, $actor, $item->product_id, -(int) $item->quantity, 'invoice:'.$invoice->id.':'.$item->product_id, 'Vente '.$invoice->number, $invoice->id);
        }
    }
}
