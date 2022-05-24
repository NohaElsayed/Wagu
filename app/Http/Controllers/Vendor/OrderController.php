<?php

namespace App\Http\Controllers\Vendor;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\CentralLogics\Helpers;
use App\CentralLogics\OrderLogic;
use App\Models\Order;
use App\Models\Category;
use App\Models\Food;
use App\Models\OrderDetail;
use App\Models\Admin;
use App\Models\RestaurantWallet;
use App\Models\AdminWallet;
use App\Models\ItemCampaign;
use App\Models\DeliveryMan;
use App\Models\BusinessSetting;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function list($status)
    {
        Order::where(['checked' => 0])->where('restaurant_id',Helpers::get_restaurant_id())->update(['checked' => 1]);
        
        $orders = Order::with(['customer'])
        ->when($status == 'searching_for_deliverymen', function($query){
            return $query->SearchingForDeliveryman();
        })
        ->when($status == 'confirmed', function($query){
            return $query->whereIn('order_status',['confirmed', 'accepted'])->whereNotNull('confirmed');
        })
        ->when($status == 'pending', function($query){
            if(config('order_confirmation_model') == 'restaurant' || Helpers::get_restaurant_data()->self_delivery_system)
            {
                return $query->where('order_status','pending');
            }
            else
            {
                return $query->where('order_status','pending')->where('order_type', 'take_away');
            }
        })
        ->when($status == 'cooking', function($query){
            return $query->where('order_status','processing');
        })
        ->when($status == 'food_on_the_way', function($query){
            return $query->where('order_status','picked_up');
        })
        ->when($status == 'delivered', function($query){
            return $query->Delivered();
        })
        ->when($status == 'ready_for_delivery', function($query){
            return $query->where('order_status','handover');
        })
        ->when($status == 'refund_requested', function($query){
            return $query->RefundRequest();
        })
        ->when($status == 'refunded', function($query){
            return $query->Refunded();
        })
        ->when($status == 'scheduled', function($query){
            return $query->Scheduled()->where(function($q){
                if(config('order_confirmation_model') == 'restaurant' || Helpers::get_restaurant_data()->self_delivery_system)
                {
                    $q->whereNotIn('order_status',['failed','canceled', 'refund_requested', 'refunded']);
                }
                else
                {
                    $q->whereNotIn('order_status',['pending','failed','canceled', 'refund_requested', 'refunded'])->orWhere(function($query){
                        $query->where('order_status','pending')->where('order_type', 'take_away');
                    });
                }

            });
        })
        ->when($status == 'all', function($query){
            return $query->where(function($query){
                $query->whereNotIn('order_status',(config('order_confirmation_model') == 'restaurant'|| Helpers::get_restaurant_data()->self_delivery_system)?['failed','canceled', 'refund_requested', 'refunded']:['pending','failed','canceled', 'refund_requested', 'refunded'])
                ->orWhere(function($query){
                    return $query->where('order_status','pending')->where('order_type', 'take_away');
                });
            });
        })
        ->when(in_array($status, ['pending','confirmed']), function($query){
            return $query->OrderScheduledIn(30);
        })
        ->Notpos()
        ->where('restaurant_id',\App\CentralLogics\Helpers::get_restaurant_id())
        ->orderBy('schedule_at', 'desc')
        ->paginate(config('default_pagination'));

        $status = trans('messages.'.$status);
        return view('vendor-views.order.list', compact('orders', 'status'));
    }

    public function search(Request $request){
        $key = explode(' ', $request['search']);
        $orders=Order::where(['restaurant_id'=>Helpers::get_restaurant_id()])->where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('id', 'like', "%{$value}%")
                    ->orWhere('order_status', 'like', "%{$value}%")
                    ->orWhere('transaction_reference', 'like', "%{$value}%");
            }
        })->Notpos()->limit(100)->get();
        return response()->json([
            'view'=>view('vendor-views.order.partials._table',compact('orders'))->render()
        ]);
    }

    public function details(Request $request,$id)
    {
        $order = Order::with(['details', 'customer'=>function($query){
            return $query->withCount('orders');
        },'delivery_man'=>function($query){
            return $query->withCount('orders');
        }])->where(['id' => $id, 'restaurant_id' => Helpers::get_restaurant_id()])->first();
          if (isset($order)) {
            if($order->restaurant->self_delivery_system)
            {
                $deliveryMen = DeliveryMan::where('restaurant_id',$order->restaurant_id)->available()->active()->get();
            }
            else
            {
                $deliveryMen = DeliveryMan::where('zone_id',$order->restaurant->zone_id)->available()->active()->get();
            }
          }
        $editing=false;
        $categories = Category::active()->get();
        $keyword = $request->query('keyword', false);
        $key = explode(' ', $keyword);
        $category = $request->query('category_id', 0);
         $products = Food::withoutGlobalScope(RestaurantScope::class)->where('restaurant_id', $order->restaurant_id)
            ->when($category, function($query)use($category){
                $query->whereHas('category',function($q)use($category){
                    return $q->whereId($category)->orWhere('parent_id', $category);
                });
            })
            ->when($keyword, function($query)use($key){
                return $query->where(function ($q) use ($key) {
                    foreach ($key as $value) {
                        $q->orWhere('name', 'like', "%{$value}%");
                    }
                });
            })
            ->latest()->paginate(10);
        if($request->session()->has('order_cart'))
        {
            $cart = session()->get('order_cart');
            if(count($cart)>0 && $cart[0]->order_id == $order->id)
            {
                $editing=true;
            }
            else
            {
                session()->forget('order_cart');
            }
        }
         $deliveryMen=Helpers::deliverymen_list_formatting($deliveryMen);
            
        if (isset($order)) {
            return view('vendor-views.order.order-view', compact('order','editing','categories','category','products','deliveryMen','keyword'));
        } else {
            Toastr::info('No more orders!');
            return back();
        }
    }
 
    public function status(Request $request)
    {
        $request->validate([
            'id' => 'required',
            'order_status' => 'required|in:confirmed,processing,handover,delivered,canceled'
        ],[
            'id.required' => 'Order id is required!'
        ]);

        $order = Order::where(['id' => $request->id, 'restaurant_id' => Helpers::get_restaurant_id()])->first();

        if($order->delivered != null)
        {
            Toastr::warning(trans('messages.cannot_change_status_after_delivered'));
            return back();
        }

        if($request['order_status']=='canceled' && !config('canceled_by_restaurant'))
        {
            Toastr::warning(trans('messages.you_can_not_cancel_a_order'));
            return back();
        }

        if($request['order_status']=='canceled' && $order->confirmed)
        {
            Toastr::warning(trans('messages.you_can_not_cancel_after_confirm'));
            return back();
        }



        if($request['order_status']=='delivered' && $order->order_type != 'take_away' && !Helpers::get_restaurant_data()->self_delivery_system)
        {
            Toastr::warning(trans('messages.you_can_not_delivered_delivery_order'));
            return back();
        }

        if($request['order_status'] =="confirmed")
        {
            if(!Helpers::get_restaurant_data()->self_delivery_system && config('order_confirmation_model') == 'deliveryman' && $order->order_type != 'take_away')
            {
                Toastr::warning(trans('messages.order_confirmation_warning'));
                return back();
            }
        }

        if ($request->order_status == 'delivered') {
            $order_delivery_verification = (boolean)\App\Models\BusinessSetting::where(['key' => 'order_delivery_verification'])->first()->value;
            if($order_delivery_verification)
            {
                if($request->otp)
                {
                    if($request->otp != $order->otp)
                    {
                        Toastr::warning(trans('messages.order_varification_code_not_matched'));
                        return back();
                    }
                }
                else
                {
                    Toastr::warning(trans('messages.order_varification_code_is_required'));
                    return back();
                }
            }

            if($order->transaction  == null)
            {
                if($order->payment_method == 'cash_on_delivery')
                {
                    $ol = OrderLogic::create_transaction($order,'restaurant', null);
                }
                else{
                    $ol = OrderLogic::create_transaction($order,'admin', null);
                }
                

                if(!$ol)
                {
                    Toastr::warning(trans('messages.faield_to_create_order_transaction'));
                    return back();
                }
            }

            $order->payment_status = 'paid';

            $order->details->each(function($item, $key){
                if($item->food)
                {
                    $item->food->increment('order_count');
                }
            });
            $order->customer->increment('order_count');
        } 
        if($request->order_status == 'canceled' || $request->order_status == 'delivered')
        {
            if($order->delivery_man)
            {
                $dm = $order->delivery_man;
                $dm->current_orders = $dm->current_orders>1?$dm->current_orders-1:0;
                $dm->save();
            }                 
        }  

        if($request->order_status == 'delivered')
        {
            $order->restaurant->increment('order_count');
            if($order->delivery_man)
            {
                $order->delivery_man->increment('order_count');
            }
            
        }

        $order->order_status = $request->order_status;
        $order[$request['order_status']] = now();
        $order->save();
        if(!Helpers::send_order_notification($order))
        {
            Toastr::warning(trans('messages.push_notification_faild'));
        }

        Toastr::success(trans('messages.order').' '.trans('messages.status_updated'));
        return back();
    }

    public function update_shipping(Request $request, $id)
    {
        $request->validate([
            'contact_person_name' => 'required',
            'address_type' => 'required',
            'contact_person_number' => 'required',
            'address' => 'required'
        ]);

        $address = [
            'contact_person_name' => $request->contact_person_name,
            'contact_person_number' => $request->contact_person_number,
            'address_type' => $request->address_type,
            'address' => $request->address,
            'longitude' => $request->longitude,
            'latitude' => $request->latitude,
            'created_at' => now(),
            'updated_at' => now()
        ];

        DB::table('customer_addresses')->where('id', $id)->update($address);
        Toastr::success('Delivery address updated!');
        return back();
    }

    public function generate_invoice($id)
    {
        $order = Order::where(['id' => $id, 'restaurant_id' => Helpers::get_restaurant_id()])->first();
        return view('vendor-views.order.invoice', compact('order'));
    }

    public function add_payment_ref_code(Request $request, $id)
    {
        Order::where(['id' => $id, 'restaurant_id' => Helpers::get_restaurant_id()])->update([
            'transaction_reference' => $request['transaction_reference']
        ]);

        Toastr::success('Payment reference code is added!');
        return back();
    }
     public function edit(Request $request, Order $order)
    {
        $order = Order::with(['details', 'restaurant'=>function($query){
            return $query->withCount('orders');
        }, 'customer'=>function($query){
            return $query->withCount('orders');
        },'delivery_man'=>function($query){
            return $query->withCount('orders');
        }, 'details.food'=>function($query){
            return $query->withoutGlobalScope(RestaurantScope::class);
        }, 'details.campaign'=>function($query){
            return $query->withoutGlobalScope(RestaurantScope::class);
        }])->where(['id' => $order->id])->Notpos()->first();
        if($request->cancle)
        {
            if ($request->session()->has(['order_cart'])) {
                session()->forget(['order_cart']);
            }
            return back();
        }
        $cart = collect([]);
        foreach($order->details as $details)
        {
            unset($details['food_details']);
            $details['status']=true;
            $cart->push($details);
        }

        if ($request->session()->has('order_cart')) {
            session()->forget('order_cart');
        } else {
            $request->session()->put('order_cart', $cart);
        }
        return back();
    }
    public function add_to_cart(Request $request)
    {
        if($request->item_type=='food')
        {
            $product = Food::find($request->id);
        }
        else
        {
            $product = ItemCampaign::find($request->id);
        }

        $data = new OrderDetail();
        if($request->order_details_id)
        {
            $data['id'] = $request->order_details_id;
        }
        
        $data['food_id'] = $request->item_type=='food'?$product->id:null;
        $data['item_campaign_id'] = $request->item_type=='campaign'?$product->id:null;
        $data['order_id']=$request->order_id;
        $str = '';
        $price = 0;
        $addon_price = 0;

        //Gets all the choice values of customer choice option and generate a string like Black-S-Cotton
        foreach (json_decode($product->choice_options) as $key => $choice) {
            if ($str != null) {
                $str .= '-' . str_replace(' ', '', $request[$choice->name]);
            } else {
                $str .= str_replace(' ', '', $request[$choice->name]);
            }
        }
        $data['variant'] = json_encode([]);
        $data['variation'] = json_encode([]);
        if ($request->session()->has('order_cart') && !isset($request->cart_item_key)) {
            if (count($request->session()->get('order_cart')) > 0) {
                foreach ($request->session()->get('order_cart') as $key => $cartItem) {
                    if ($cartItem['food_id'] == $request['id'] && $cartItem['status']==true) {
                        if(count(json_decode($cartItem['variation'], true))>0)
                        {
                            if(json_decode($cartItem['variation'],true)[0]['type'] == $str)
                            {
                                return response()->json([
                                'data' => 1
                                ]);
                            }
                        }
                        else
                        {
                            return response()->json([
                               'data' => 1
                            ]);
                        }

                    }
                }

            }
        }
        //Check the string and decreases quantity for the stock
        if ($str != null) {
            $count = count(json_decode($product->variations));
            for ($i = 0; $i < $count; $i++) {
                if (json_decode($product->variations)[$i]->type == $str) {
                    $price = json_decode($product->variations)[$i]->price;
                }
            }
            $data['variation'] = json_encode([["type"=>$str,"price"=>$price]]);
        } else {
            $price = $product->price;
        }

        $data['quantity'] = $request['quantity'];
        $data['price'] = $price;
        $data['status'] = true;
        $data['discount_on_food'] = Helpers::product_discount_calculate($product, $price,$product->restaurant);
        $data["discount_type"] = "discount_on_product";
        $data["tax_amount"] = Helpers::tax_calculate($product, $price);
        $add_ons = [];
        $add_on_qtys = [];
        
        if($request['addon_id'])
        {
            foreach($request['addon_id'] as $id)
            {
                $addon_price+= $request['addon-price'.$id]*$request['addon-quantity'.$id];
                $add_on_qtys[]=$request['addon-quantity'.$id];
            } 
            $add_ons = $request['addon_id'];
        }

        $addon_data = Helpers::calculate_addon_price(\App\Models\AddOn::whereIn('id',$add_ons)->get(), $add_on_qtys);
        $data['add_ons'] = json_encode($addon_data['addons']);
        $data['total_add_on_price'] = $addon_data['total_add_on_price'];
        // dd($data);
        $cart = $request->session()->get('order_cart', collect([]));
        if(isset($request->cart_item_key))
        {
            $cart[$request->cart_item_key] = $data;
            return response()->json([
                'data' => 2
            ]);
        }
        else
        {
            $cart->push($data);
        }

        return response()->json([
            'data' => 0
        ]);
    }

    public function update(Request $request, Order $order)
    {
        $order = Order::with(['details', 'restaurant'=>function($query){
            return $query->withCount('orders');
        }, 'customer'=>function($query){
            return $query->withCount('orders');
        },'delivery_man'=>function($query){
            return $query->withCount('orders');
        }, 'details.food'=>function($query){
            return $query->withoutGlobalScope(RestaurantScope::class);
        }, 'details.campaign'=>function($query){
            return $query->withoutGlobalScope(RestaurantScope::class);
        }])->where(['id' => $order->id])->Notpos()->first();
        
        if(!$request->session()->has('order_cart'))
        {
            Toastr::error(trans('messages.order_data_not_found'));
            return back();
        }
        $cart = $request->session()->get('order_cart', collect([]));
        $restaurant = $order->restaurant;
        $coupon = null;
        $total_addon_price = 0;
        $product_price = 0;
        $restaurant_discount_amount = 0;
        if($order->coupon_code)
        {
            $coupon = Coupon::where(['code' => $request['coupon_code']])->first();
        }
        foreach ($cart as $c) {
            if($c['status'] == true)
            {
                unset($c['status']);
                if ($c['item_campaign_id'] != null) 
                {
                    $product = ItemCampaign::find($c['item_campaign_id']);
                    if ($product) {

                        $price = $c['price'];

                        $product = Helpers::product_data_formatting($product);
            
                        $c->food_details = json_encode($product);
                        $c->updated_at = now();
                        if(isset($c->id))
                        {   
                            OrderDetail::where('id', $c->id)->update(
                                [
                                    'food_id' => $c->food_id,
                                    'item_campaign_id' => $c->item_campaign_id,
                                    'food_details' => $c->food_details,
                                    'quantity' => $c->quantity,
                                    'price' => $c->price,
                                    'tax_amount' => $c->tax_amount,
                                    'discount_on_food' => $c->discount_on_food,
                                    'discount_type' => $c->discount_type,
                                    'variant' => $c->variant,
                                    'variation' => $c->variation,
                                    'add_ons' => $c->add_ons,
                                    'total_add_on_price' => $c->total_add_on_price,
                                    'updated_at' => $c->updated_at
                                ]
                            );
                        }
                        else
                        {
                            $c->save();
                        }
                        
                        $total_addon_price += $c['total_add_on_price'];
                        $product_price += $price*$c['quantity'];
                        $restaurant_discount_amount += $c['discount_on_food']*$c['quantity'];
                    } else {
                        Toastr::error(trans('messages.food_not_found'));
                        return back();
                    }
                } else {
                    $product = Food::find($c['food_id']);
                    if ($product) {

                        $price = $c['price'];

                        $product = Helpers::product_data_formatting($product);
            
                        $c->food_details = json_encode($product);
                        $c->updated_at = now();
                        if(isset($c->id))
                        {   
                            OrderDetail::where('id', $c->id)->update(
                                [
                                'food_id' => $c->food_id,
                                'item_campaign_id' => $c->item_campaign_id,
                                'food_details' => $c->food_details,
                                'quantity' => $c->quantity,
                                'price' => $c->price,
                                'tax_amount' => $c->tax_amount,
                                'discount_on_food' => $c->discount_on_food,
                                'discount_type' => $c->discount_type,
                                'variant' => $c->variant,
                                'variation' => $c->variation,
                                'add_ons' => $c->add_ons,
                                'total_add_on_price' => $c->total_add_on_price,
                                'updated_at' => $c->updated_at
                            ]
                            );
                        }
                        else
                        {
                            $c->save();
                        }

                        $total_addon_price += $c['total_add_on_price'];
                        $product_price += $price*$c['quantity'];
                        $restaurant_discount_amount += $c['discount_on_food']*$c['quantity'];
                    } else {
                        Toastr::error(trans('messages.food_not_found'));
                        return back();
                    }
                }
            }
            else
            {
                $c->delete();
            }
        }

        $restaurant_discount = Helpers::get_restaurant_discount($restaurant);
        if(isset($restaurant_discount))
        {
            if($product_price + $total_addon_price < $restaurant_discount['min_purchase'])
            {
                $restaurant_discount_amount = 0;
            }

            if($restaurant_discount_amount > $restaurant_discount['max_discount'])
            {
                $restaurant_discount_amount = $restaurant_discount['max_discount'];
            }
        }
        $order->delivery_charge = $order->original_delivery_charge;
        if($coupon)
        {
            if($coupon->coupon_type == 'free_delivery')
            {
                $order->delivery_charge = 0;
                $coupon = null;
            }
        }
        
        if($order->restaurant->free_delivery)
        {
            $order->delivery_charge = 0;
        }

        $coupon_discount_amount = $coupon ? CouponLogic::get_discount($coupon, $product_price + $total_addon_price - $restaurant_discount_amount) : 0; 
        $total_price = $product_price + $total_addon_price - $restaurant_discount_amount - $coupon_discount_amount;

        $tax = $restaurant->tax;
        $total_tax_amount= ($tax > 0)?(($total_price * $tax)/100):0;
        if($restaurant->minimum_order > $product_price + $total_addon_price )
        {
            Toastr::error(trans('messages.you_need_to_order_at_least', ['amount'=>$restaurant->minimum_order.' '.Helpers::currency_code()]));
            return back();
        }

        $free_delivery_over = BusinessSetting::where('key', 'free_delivery_over')->first()->value;
        if(isset($free_delivery_over))
        {
            if($free_delivery_over <= $product_price + $total_addon_price - $coupon_discount_amount - $restaurant_discount_amount)
            {
                $order->delivery_charge = 0;
            }
        }
        $total_order_ammount = $total_price + $total_tax_amount + $order->delivery_charge;
        $adjustment = $order->order_amount - $total_order_ammount;

        $order->coupon_discount_amount = $coupon_discount_amount;
        $order->restaurant_discount_amount= $restaurant_discount_amount;
        $order->total_tax_amount= $total_tax_amount;
        $order->order_amount = $total_order_ammount;
        $order->adjusment = $adjustment;
        $order->edited = true;
        $order->save();
        session()->forget('order_cart');
        Toastr::success(trans('messages.order_updated_successfully'));
        return back();
    }

    
    public function quick_view(Request $request)
    {

        $product = $product = Food::findOrFail($request->product_id);
        $item_type = 'food';
        $order_id = $request->order_id;
        
        return response()->json([
            'success' => 1,
            'view' => view('admin-views.order.partials._quick-view', compact('product', 'order_id','item_type'))->render(),
        ]);
    }

    public function quick_view_cart_item(Request $request)
    {
        $cart_item = session('order_cart')[$request->key];
        $order_id = $request->order_id;
        $item_key = $request->key;
        $product = $cart_item->food?$cart_item->food:$cart_item->campaign;
        $item_type = $cart_item->food?'food':'campaign';
        
        return response()->json([
            'success' => 1,
            'view' => view('admin-views.order.partials._quick-view-cart-item', compact('order_id','product', 'cart_item', 'item_key','item_type'))->render(),
        ]);
    }

    public function remove_from_cart(Request $request)
    {
        $cart = $request->session()->get('order_cart', collect([]));
        $cart[$request->key]->status=false;
        $request->session()->put('order_cart', $cart);

        return response()->json([],200);
    }


}
