<?php

namespace Increment\Imarket\Cart\Http;

use Illuminate\Http\Request;
use App\Http\Controllers\APIController;
use Increment\Imarket\Cart\Models\Checkout;
use Increment\Imarket\Cart\Models\CheckoutItem;
use Increment\Imarket\Merchant\Models\Merchant;
use Increment\Imarket\Location\Models\Location;
use Increment\Imarket\Product\Models\Product;
use Increment\Imarket\Delivery\Models\DeliveryFee;
use Increment\Imarket\Product\Models\Pricing;
use Increment\Imarket\Payment\Models\StripeWebhook;
use Carbon\Carbon;
use App\Jobs\Notifications;
use Illuminate\Support\Facades\DB;

class CheckoutController extends APIController
{
  protected $subTotal = 0;
  protected $total = 0;
  protected $tax = 0;
  public $cartClass = 'Increment\Imarket\Cart\Http\CartController';
  public $checkoutItemClass = 'Increment\Imarket\Cart\Http\CheckoutItemController';
  public $merchantClass = 'Increment\Imarket\Merchant\Http\MerchantController';
  public $locationClass = 'Increment\Imarket\Location\Http\LocationController';
  public $deliveryClass = 'Increment\Imarket\Delivery\Http\DeliveryController';
  public $messengerGroupClass = 'Increment\Messenger\Http\MessengerGroupController';
  public $ratingClass = 'Increment\Common\Rating\Http\RatingController';

  function __construct(){
  	$this->model = new Checkout();

    $this->notRequired = array(
      'coupon_id',
      'order_number',
      'payment_type',
      'payment_payload',
      'payment_payload_value',
      'notes',
      'tendered_amount'
    );
  }

  public function retrieve(Request $request){
    $data = $request->all();
    $this->model = new Checkout();
    $this->retrieveDB($data);
    $result = $this->response['data'];
    if(sizeof($result) > 0){
      $i = 0;
      foreach ($result as $key) {
        $this->response['data'][$i]['account'] = $this->retrieveAccountDetails($result[$i]['account_id']);
        $i++;
      }
    }
    return $this->response();
  }

  public function retrieveByRider(Request $request){
    $data = $request->all();
    $this->model = new Checkout();
    $this->retrieveDB($data);
    $result = $this->response['data'];
    if(sizeof($result) > 0){
      $i = 0;
      foreach ($result as $key) {
        $this->response['data'][$i]['name'] = $this->retrieveNameOnly($result[$i]['account_id']);
        $locations = app($this->locationClass)->getAndManageLocation('id', $result[$i]['location_id'], $result[$i]['merchant_id']);
        $this->response['data'][$i]['merchant_location'] = $locations['merchant_location'];
        $this->response['data'][$i]['location'] = $locations['location'];
        $this->response['data'][$i]['distance'] = $locations['distance'];
        $this->response['data'][$i]['customer_rating'] = app($this->ratingClass)->getRatingByPayload2($data['rider'], 'customer', $result[$i]['account_id'], 'checkout', $result[$i]['id']);
        $this->response['data'][$i]['merchant_rating'] = app($this->ratingClass)->getRatingByPayload2($data['rider'], 'merchant', $result[$i]['merchant_id'], 'checkout', $result[$i]['id']);
        $i++;
      }
    }
    return $this->response();
  }

  public function summaryOfDailyOrders(Request $request){
    $data = $request->all();

    $results = Checkout::where('created_at', '>=', $data['date'].' 00:00:00')
                    ->where('created_at', '<=', $data['date'].' 23:59:59')
                    ->where('merchant_id', '=', $data['merchant_id'])
                    ->groupBy('status')
                    ->get(array(
                        DB::raw('SUM(total) as `total`'),
                        'status'
                    ));

    $this->response['data'] = $results;
    return $this->response();;
  }

  public function summaryOfOrders(Request $request){
    $data = $request->all();
    $results = Checkout::where('created_at', '>=', $data['date'].'-01')
                    ->where('created_at', '<=', $data['date'].'-31')
                    ->where('merchant_id', '=', $data['merchant_id'])
                    ->groupBy('date', 'status')
                    ->orderBy('date', 'ASC') // or ASC
                    ->get(array(
                        DB::raw('DATE(`created_at`) AS `date`'),
                        DB::raw('SUM(total) as `total`'),
                        'status'
                    ));

    $completedSeries = array();
    $cancelledSeries = array();
    $categories = array();

    $numberOfDays = Carbon::createFromFormat('Y-m-d', $data['date'].'-01')->daysInMonth;
    for ($i = 1; $i <= $numberOfDays; $i++) {
      $completedSeries[] = 0;
      $cancelledSeries[] = 0;
      $categories[] = $i;
    }

    foreach ($results as $key) {
      $index = intval(substr($key->date, 8)) - 1;
      // echo $key->date.'/'.$index;
      if($key->status == 'completed'){
        $completedSeries[$index] = $key->total;
      }else if($key->status == 'cancelled'){
        $cancelledSeries[$index] = $key->total;
      }
    }

    $this->response['data'] = array(
      'series' => array(array(
        'name'  => 'Completed',
        'data'  => $completedSeries
      ), array(
        'name'  => 'Cancelled',
        'data'  => $cancelledSeries
      )),
      'categories' => $categories
    );
    return $this->response();;
  }

  public function retrieveOrders(Request $request){
    $data = $request->all();
    $this->model = new Checkout();
    $this->retrieveDB($data);
    $result = $this->response['data'];
    if(sizeof($result) > 0){
      $i = 0;
      foreach ($result as $key) {
        $delivery = app($this->deliveryClass)->getDeliveryDetails('checkout_id', $key['id']);
        $accountId = app($this->merchantClass)->getByParamsReturnByParam('id', $key['merchant_id'], 'account_id');
        $this->response['data'][$i]['tendered_amount'] =  $key['tendered_amount'] == null ? 0 :  doubleval($key['tendered_amount']);
        $change =  $key['tendered_amount'] != null ? doubleval($key['tendered_amount']) - doubleval($key['total']) : 0;
        $this->response['data'][$i]['name'] = $this->retrieveNameOnly($key['account_id']);
        $this->response['data'][$i]['location'] = app($this->locationClass)->getAppenedLocationByParams('id', $key['location_id'], $key['merchant_id']);
        $this->response['data'][$i]['assigned_rider'] = $delivery;
        $this->response['data'][$i]['change'] = $change;
        $this->response['data'][$i]['coupon'] = null;
        $this->response['data'][$i]['date'] = Carbon::createFromFormat('Y-m-d H:i:s', $result[$i]['created_at'])->copy()->tz($this->response['timezone'])->format('F j, Y h:i A');
        $this->response['data'][$i]['message'] = $key['status'] !== 'completed' ? app($this->messengerGroupClass)->getUnreadMessagesByParams('title', $key['code'], $accountId) : null;
        $this->response['data'][$i]['customer_rating'] = app($this->ratingClass)->getRatingByPayload2($accountId, 'customer', $result[$i]['account_id'], 'checkout', $result[$i]['id']);
        $this->response['data'][$i]['rider_rating'] = $delivery ? app($this->ratingClass)->getRatingByPayload2($accountId, 'rider', $delivery['id'], 'checkout', $result[$i]['id']) : null;
        $i++;
      }
    }
    $this->response['size'] = Checkout::where($data['condition'][0]['column'], $data['condition'][0]['clause'], $data['condition'][0]['value'])->count();
    return $this->response();
  }


  public function retrieveOrdersMobile(Request $request){
    $data = $request->all();
    $this->model = new Checkout();
    $this->retrieveDB($data);
    $result = $this->response['data'];
    $array = array();
    if(sizeof($result) > 0){
      $i = 0;
      foreach ($result as $key) {
        $accountId = app($this->merchantClass)->getByParamsReturnByParam('id', $key['merchant_id'], 'account_id');
        $object = array(
          'location'  => app($this->locationClass)->getAppenedLocationByParams('id', $key['location_id'], $key['merchant_id']),
          'date'      => Carbon::createFromFormat('Y-m-d H:i:s', $result[$i]['created_at'])->copy()->tz($this->response['timezone'])->format('F j, Y h:i A'),
          'message'   => $key['status'] !== 'completed' ? app($this->messengerGroupClass)->getUnreadMessagesByParams('title', $key['code'], $accountId) : null,
          'broadcast' => null,
          'status'    => $key['status'],
          'order_number'    => $key['order_number'],
          'id'    => $key['id'],
          'total'    => $key['total'],
          'currency'    => $key['currency']
        );
        $array[] = $object;
        $i++;
      }
    }
    $this->response['data'] = $array;
    $this->response['size'] = Checkout::where($data['condition'][0]['column'], $data['condition'][0]['clause'], $data['condition'][0]['value'])->count();
    return $this->response();
  }

  public function create(Request $request){
    $data = $request->all();
    $prefix = app($this->merchantClass)->getByParamsReturnByParam('id', $data['merchant_id'], 'prefix');
    $counter = Checkout::where('merchant_id', '=', $data['merchant_id'])->count();
    $location = app('Increment\Imarket\Location\Http\LocationController')->getByParams('merchant_id', $data['merchant_id']);
    $data['order_number'] = $prefix ? $prefix.$this->toCode($counter) : $this->toCode($counter);
    $data['code'] = $this->generateCode();
    $distance = app('Increment\Imarket\Location\Http\LocationController')->getLongLatDistance($data['latitude'], $data['longitude'], $location['latitude'], $location['longitude']);
    $distanceCalc = intdiv($distance, 1);
    $locationCode = Location::select('id','code')->where('merchant_id', '=', $data['merchant_id'])->get();
    $data['location_id'] = $locationCode[0]['id'];
    $deliveryScope = DeliveryFee::where('scope','=',$locationCode[0]['code'])->get();
    //compare params in deliveryFee for calculation
    //check if distance is under minimum distance
    if ($deliveryScope[0]['minimum_distance'] <= $distanceCalc){
      $data['shipping_fee'] = $deliveryScope[0]['minimum_charge'];
    }else{
      $data['shipping_fee'] = $deliveryScope[0]['minimum_charge']+(($distanceCalc-$deliveryScope[0]['minimum_distance'])*$deliveryScope[0]['addition_charge_per_distance']);
    }
    $this->model = new Checkout();
    $this->insertDB($data);
    if($this->response['data'] > 0){
      // create items
      $cartItems = app($this->cartClass)->getItemsInArray('account_id', $data['account_id']);
      if(sizeof($cartItems) > 0){
        $items = array();
        $i = 0;
        foreach ($cartItems as $key => $value) {
          $item = array(
            'account_id'  => $data['account_id'],
            'checkout_id' => $this->response['data'],
            'payload'     => 'product',
            'payload_value' => $cartItems[$i]['id'],
            'qty'       => $cartItems[$i]['quantity'],
            'product_attribute_id' => isset($cartItems[$i]['product_attribute_id']) ? $cartItems[$i]['product_attribute_id'] : NULL,
            'price'       => $cartItems[$i]['price'][0]['price'],
            'status'       => 'pending',
            'created_at' => Carbon::now()
          );
          $items[] = $item;
          $i++;
        }

        app($this->checkoutItemClass)->insertInArray($items);
        app($this->cartClass)->emptyItems($data['account_id']);

        $data['merchant'] = null;
        $merchant = Merchant::select('account_id')->where('id', '=', $data['merchant_id'])->get();
        if (sizeof($merchant) > 0) {
          $data['merchant'] = $merchant[0]['code'];
        }
        Notifications::dispatch('orders', $data);
      }
    }

    return $this->response();
  }

  public function toCode($size){
    $length = strlen((string)$size);
    $code = '00000001';
    return substr_replace($code, $size, intval(7 - $length));
  }

  public function getshippingFee(Request $request){
    $data = $request->all();
    $location = app('Increment\Imarket\Location\Http\LocationController')->getByParams('merchant_id', $data['merchant_id']);
    $distance = app('Increment\Imarket\Location\Http\LocationController')->getLongLatDistance($data['latitude'], $data['longitude'], $location['latitude'], $location['longitude']);
    $distanceCalc = ($distance < 1) ? 1 : intdiv($distance, 1);
    $locationCode = Location::select('code')->where('merchant_id', '=', $data['merchant_id'])->get();
    $deliveryScope = DeliveryFee::where('scope','=',$locationCode[0]['code'])->get();
    //compare params in deliveryFee for calculation
    //check if distance is under minimum distance
    if ($distanceCalc <= $deliveryScope[0]['minimum_distance'] ){
      return $deliveryScope[0]['minimum_charge'];
    }else{
      return $deliveryScope[0]['minimum_charge']+(($distanceCalc-$deliveryScope[0]['minimum_distance'])*$deliveryScope[0]['addition_charge_per_distance']);
    }
  }
  
  public function getByParamsReturnByParam($column, $value, $param){
    $result = Checkout::where($column, '=', $value)->get();
    return sizeof($result) > 0 ? $result[0][$param] : null;
  }

  public function getByParams($column, $value){
    $result = Checkout::where($column, '=', $value)->get();
    return sizeof($result) > 0 ? $result[0] : null;
  }

  public function generateCode(){
    $code = 'che_'.substr(str_shuffle($this->codeSource), 0, 60);
    $codeExist = Checkout::where('code', '=', $code)->get();
    if(sizeof($codeExist) > 0){
      $this->generateCode();
    }else{
      return $code;
    }
  }
}
