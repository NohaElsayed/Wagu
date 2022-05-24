<?php

namespace App\Http\Controllers\Admin;

use App\Models\DeliveryMan;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class HistoryController extends Controller
{

     public function history(Request $request,$id)
    {
        $delivery_man = DeliveryMan::find($id);
        return view('admin-views.delivery-man.history-delivery',compact('delivery_man'));
    }
    public function shift(){
        $delivery_man = DeliveryMan::all();
        return view('admin-views.delivery-man.shifts',compact('delivery_man'));
    }
}
