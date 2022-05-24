<?php

namespace App\Http\Controllers\Api\V1\Vendor;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\CentralLogics\Helpers;
use App\CentralLogics\OrderLogic;
use App\CentralLogics\CouponLogic;
use App\Models\BusinessSetting;
use App\Models\Order;
use App\Models\Coupon;
use App\Models\OrderDetail;
use App\Models\Food;
use App\Models\Restaurant;
use App\Models\ItemCampaign;
use Illuminate\Support\Facades\Validator;
use App\Models\Zone;
use Grimzy\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function place_order(Request $request)
    {
        $cart = $request['cart'];
        $restaurant = Helpers::get_restaurant_data();
        $total_addon_price = 0;
        $product_price = 0;
        $restaurant_discount_amount = 0;

        $order_details = [];
        $order = new Order();
        $order->id = 100000 + Order::all()->count() + 1;
        if (Order::find($order->id)) {
            $order->id = Order::latest()->first()->id + 1;
        }
        // $order->payment_status = 'paid';
        // $order->order_status = 'delivered';
        // $order->order_type = 'pos';
        $order->payment_method = $request->payment_method;
        $order->restaurant_id = $restaurant->id;
       // $order->user_id = $request->user_id;
        $order->delivery_charge = 0;
        $order->original_delivery_charge = 0;
        $order->checked = 1;
        $order->created_at = now();
        $order->updated_at = now();
        foreach ($cart as $c) {
            if(is_array($c))
            {
                $product = Food::find($c['id']);
                if ($product) {
                    $price = $c['price'];
                    $product->tax = $restaurant->tax;
                    $product = Helpers::product_data_formatting($product);
                    $addon_data = Helpers::calculate_addon_price(\App\Models\AddOn::whereIn('id',$c['add_ons'])->get(), $c['add_on_qtys']);
                    $or_d = [
                        'food_id' => $c['id'],
                        'item_campaign_id' => null,
                        'food_details' => json_encode($product),
                        'quantity' => $c['quantity'],
                        'price' => $price,
                        'tax_amount' => Helpers::tax_calculate($product, $price),
                        'discount_on_food' => Helpers::product_discount_calculate($product, $price, $restaurant),
                        'discount_type' => 'discount_on_product',
                        'variant' => json_encode($c['variant']),
                        'variation' => json_encode([$c['variations']]),
                        'add_ons' => json_encode($addon_data['addons']),
                        'total_add_on_price' => $addon_data['total_add_on_price'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                    $total_addon_price += $or_d['total_add_on_price'];
                    $product_price += $price*$or_d['quantity'];
                    $restaurant_discount_amount += $or_d['discount_on_food']*$or_d['quantity'];
                    $order_details[] = $or_d;
                }
            }
        }

        if(isset($cart['discount']))
        {
            $restaurant_discount_amount += $cart['discount_type']=='percent'&&$cart['discount']>0?((($product_price + $total_addon_price - $restaurant_discount_amount) * $cart['discount'])/100):$cart['discount'];
        }

}
}