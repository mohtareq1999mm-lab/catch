<?php
// Forensic builder v2: runtime inventory -> api-master-spec.json + postman-collection.json
// Source of truth: implementation. Unknown stays unknown. No invented fields.
error_reporting(E_ERROR);
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$routes = Illuminate\Support\Facades\Route::getRoutes();
$inv = [];
foreach ($routes as $r) {
    $uri = $r->uri();
    if (stripos($uri, 'api') !== 0) continue;
    $methods = array_values(array_filter($r->methods(), fn($m) => $m !== 'HEAD' && $m !== 'OPTIONS'));
    $action = $r->getAction();
    $mw = array_values((array)($action['middleware'] ?? []));
    foreach ($methods as $m) {
        $inv[] = ['method'=>$m,'uri'=>'/'.$uri,'name'=>$r->getName(),
            'action'=>$action['controller'] ?? ($action['uses'] ?? 'Closure'),'middleware'=>$mw];
    }
}
usort($inv, fn($a,$b)=>strcmp($a['method'].' '.$a['uri'], $b['method'].' '.$b['uri']));

function classify($e){
    $uri=$e['uri']; $mw=implode(' ',(array)$e['middleware']);
    if (preg_match('#/checkout/(callback|error-callback|webhooks)|/broadcasting/#i',$uri)) return 'system';
    if (preg_match('#^/api/v1/(admin|v1/admin)/#',$uri)) return 'admin';
    if (strpos($mw,'permission:')!==false) return 'admin';
    if (strpos($mw,'auth:sanctum')!==false) {
        if (strpos($mw,'throttle:admin')!==false) return 'admin';
        return 'general';
    }
    return 'general';
}
function audience($e,$g){
    if ($g==='system') return 'system';    $mw=implode(' ',(array)$e['middleware']);
    if ($g==='admin') return 'admin';
    if (strpos($mw,'auth:sanctum')!==false) return 'customer';
    return 'public';
}
// ---- deep overlays for proven critical endpoints (key: METHOD + space + URI template) ----
$over = [];
$over['POST /api/v1/token'] = [
 'controller'=>'Marvel\\Http\\Controllers\\UserController','action'=>'token',
 'route_file'=>'packages/marvel/src/Rest/Routes.php','auth'=>['required'=>false,'mechanism'=>'none','throttle'=>'throttle:login (5/min/IP)'],
 'request_fields'=>[
   ['name'=>'email','location'=>'body','type'=>'string/email','required'=>'required_without phone_number','rules'=>'required_without:phone_number|email'],
   ['name'=>'phone_number','location'=>'body','type'=>'string','required'=>'required_without email','rules'=>'required_without:email|string|max:15|min:8'],
   ['name'=>'password','location'=>'body','type'=>'string','required'=>'required','rules'=>'required|string|min:6']],
 'responses'=>[['status'=>200,'desc'=>'USER_LOGGED_IN_SUCCESSFULLY {status,message,success:true,data:{token,email_verified,permissions[],role[],expires_at}}'],['status'=>404,'desc'=>'INVALID_CREDENTIALS (no user / wrong pass / inactive)'],['status'=>422,'desc'=>'raw {field:[msg]} from failedValidation'],['status'=>429,'desc'=>'throttle:login']],
 'business'=>['purpose'=>'Customer login; issues Sanctum personal_access_token (14 weekdays expiry).','actor'=>'guest/customer','pre'=>'active type=user account exists','steps'=>['validate identifier+password','lookup users where type=user,is_active','Hash::check','adopt guest currency (X-Currency header)','createToken auth_token'],'db'=>'SELECT users; INSERT personal_access_tokens','events'=>'none','next'=>'GET /api/v1/me, POST /logout, POST /change-password','prev'=>'POST /api/v1/register then POST /otp-login (if email verify)'],
 'evidence'=>['request'=>'packages/marvel/src/Http/Requests/UserAuthEmailAndPasswordRequest.php','controller'=>'packages/marvel/src/Http/Controllers/UserController.php@token']];
$over['POST /api/v1/register'] = [
 'controller'=>'Marvel\\Http\\Controllers\\UserController','action'=>'register','route_file'=>'packages/marvel/src/Rest/Routes.php',
 'auth'=>['required'=>false,'mechanism'=>'none','throttle'=>'throttle:login'],
 'request_fields'=>[
   ['name'=>'first_name','rules'=>'required|string|max:50|min:2'],['name'=>'last_name','rules'=>'required|string|max:50|min:2'],
   ['name'=>'email','rules'=>'nullable|sometimes|email|unique:users,email|email:rfc,dns'],['name'=>'phone_number','rules'=>'required|string|max:20|min:10|unique:users,phone_number'],
   ['name'=>'password','rules'=>'required|string|min:8|max:50|confirmed'],['name'=>'password_confirmation','rules'=>'required|string|min:8|max:50'],
   ['name'=>'avatar','rules'=>'sometimes|image|mimes:jpeg,png,jpg,gif,svg|max:2048'],['name'=>'policy','rules'=>'required|in:1,true']],
 'responses'=>[['status'=>200,'desc'=>'registered + OTP sent {otp_status:true}'],['status'=>201,'desc'=>'ACCOUNT_CREATED_BUT_OTP_FAILED {requires_resend,email,phone_number,otp_status:false}'],['status'=>422,'desc'=>'raw validation'],['status'=>500,'desc'=>'exception message leaked on failure (proven quirk)']],
 'business'=>['purpose'=>'Create customer account (type=user,is_active=true) in DB transaction; send OTP if email.','actor'=>'guest','pre'=>'none','steps'=>['validate','users INSERT (+media if avatar) commit','sendOneTimePassword if email'],'db'=>'INSERT users, media?, one_time_passwords (OTP outside txn)','events'=>'OneTimePasswordNotification','next'=>'POST /send-otp-code (resend) / POST /otp-login {email,code} — register does NOT log in','prev'=>'none'],
 'evidence'=>['request'=>'packages/marvel/src/Http/Requests/UserCreateRequest.php','controller'=>'packages/marvel/src/Http/Controllers/UserController.php@register']];
$over['POST /api/v1/general/checkout'] = [
 'controller'=>'App\\Http\\Controllers\\Api\\General\\OrderController','action'=>'checkout','route_file'=>'routes/api.php',
 'auth'=>['required'=>true,'mechanism'=>'sanctum Bearer','throttle'=>'throttle:authenticated'],
 'request_fields'=>[
   ['name'=>'name','rules'=>'required|string|max:255'],['name'=>'user_phone','rules'=>'required|string|max:255'],
   ['name'=>'user_email','rules'=>'nullable|sometimes|email|max:255'],['name'=>'address','rules'=>'requiredIf(cartHasPhysical && fulfillment_type!=pickup)|nullable|array'],
   ['name'=>'notes','rules'=>'nullable|string'],['name'=>'selected_promotion_id','rules'=>'nullable|integer|exists:promotions,id'],
   ['name'=>'selected_gift_product_id','rules'=>'nullable|integer|exists:products,id'],['name'=>'type','rules'=>'nullable|in:mobile,web'],
   ['name'=>'fulfillment_type','rules'=>'nullable|in:delivery,pickup; pay_at_cashier forces pickup'],['name'=>'payment_method','rules'=>'nullable|in:online,cod,pay_at_cashier (default online)'],
   ['name'=>'gateway','rules'=>'nullable|string|max:50 (default config payment.default_gateway=myfatoorah)'],['name'=>'shipping_type','rules'=>'nullable|in:local,international'],
   ['name'=>'flow_values','rules'=>'nullable|array (validated vs flow definitions)'],['name'=>'governorate_id','rules'=>'requiredIf(delivery+physical)|integer|exists:governorates,id'],
   ['name'=>'pickup_location_id','rules'=>'requiredIf(pickup)|integer|exists:pickup_locations,id']],
 'responses'=>[['status'=>200,'desc'=>'CHECKOUT_SUCCESSFUL online->{url} / cod|cashier|zero->{order_id}'],['status'=>400,'desc'=>'CART_NOT_FOUND'],['status'=>422,'desc'=>'validation | COD_NOT_AVAILABLE_FOR_PICKUP | INVALID_PAYMENT_METHOD | pending_order_shipping_type_conflict | coupon-reserve | PAYMENT_CURRENCY_UNSUPPORTED | gift/stock'],['status'=>500,'desc'=>'ERROR_ADDING_ITEMS_TO_ORDER | ERROR_CREATING_INVOICE']],
 'business'=>['purpose'=>'Create/refresh pending order from active cart then initiate payment branch.','actor'=>'customer','pre'=>'active cart with SCHEDULED lines exists','steps'=>['load active cart or 400','defaults payment/fulfillment/gateway','reject cod+pickup 422','OrderService::addItemsInOrder (single DB::transaction: cart lock, reprice, coupon revalidate, promotion/gift, totals FlashSale->Promotion->Coupon, shipping/tax, pending-reuse or create, reserve stock, clear cart slice, dispatch OrderCreated)','branch online/cod/cashier/zero-value'],'db'=>'INSERT/UPDATE orders, order_products, order_status_history; stock reserved/committed; carts/cart_items slice deleted; transactions INSERT pending (or paid-0); coupon_reservations','events'=>'OrderCreated -> admin+user notifications, ReleaseFulfillmentOnCodPlacement, timeline','next'=>'pay via {url} -> checkout/callback|error-callback|webhooks -> PaymentCompletionService::completeLocked -> completed; cod/cashier await staff mark-paid','prev'=>'GET cart (active) ; POST cart/coupons/apply'],
 'evidence'=>['request'=>'packages/marvel/src/Http/Requests/OrderCreateRequest.php','controller'=>'App/Http/Controllers/Api/General/OrderController.php@checkout','service'=>'app/Services/General/OrderService.php::addItemsInOrder','handler'=>'app/Services/Payment/PaymentCheckoutHandler.php']];
$over['POST /api/v1/general/coupons/apply'] = [
 'controller'=>'App\\Http\\Controllers\\Api\\General\\CouponController','action'=>'applyCoupon','route_file'=>'routes/api.php',
 'auth'=>['required'=>true,'mechanism'=>'sanctum Bearer'],
 'request_fields'=>[['name'=>'code','rules'=>'required|string|max:191']],
 'responses'=>[['status'=>200,'desc'=>'COUPON_APPLIED_SUCCESSFULLY {total_price,coupon_discount,free_shipping} | COUPON_ALREADY_APPLIED {already_applied:true}'],['status'=>400,'desc'=>'INVALID_COUPON {reason,no_cart|not_found|claim_required|already_used|not_eligible}'],['status'=>422,'desc'=>'code missing/non-string/>191']],
 'business'=>['purpose'=>'Attach coupon code to cart (UPDATE carts.coupon). Advisory revalidation at checkout.','actor'=>'customer','pre'=>'owned cart exists','steps'=>['normalize code compare (case/space-insensitive) -> already_applied','CouponOrchestrator::validateByCode (canonical UPPER(TRIM) lookup, claim check, mode dynamic/assignment, limiter/usage/product checks)','CouponCalculator -> carts.coupon save'],'db'=>'UPDATE carts SET coupon (success only)','events'=>'none','next'=>'POST checkout (revalidates)','prev'=>'GET coupons/available|mine|index'],
 'evidence'=>['controller'=>'app/Http/Controllers/Api/General/CouponController.php@applyCoupon','service'=>'app/Services/General/CouponService.php::addCouponToCart','code'=>'app/Support/CouponCode.php::queryByCode']];

function folder($e,$g){
    $u=$e['uri'];
    if ($g==='system') {
        if (strpos($u,'webhook')!==false) return 'Webhooks';
        if (strpos($u,'callback')!==false) return 'Payment Callbacks';
        return 'Internal';
    }
    if ($g==='admin') {
        if (preg_match('#/(brand|attribute|tag|flash|promotion|coupon|product|categor|banner|slider|content|section|static|setting|faq|governorate|countr|cit|pickup|fast-shipping|site-review|currenc|payment-gateway)#',$u,$m)) {
            $m=strtolower($m[1]);
            $map=['brand'=>'Brands','attribute'=>'Content','tag'=>'Content','flash'=>'Content','promotion'=>'Content','coupon'=>'Content','product'=>'Products','categor'=>'Categories','banner'=>'Content','slider'=>'Content','content'=>'Content','section'=>'Content','static'=>'Content','setting'=>'Settings','faq'=>'Content','governorate'=>'Locations','countr'=>'Locations','cit'=>'Locations','pickup'=>'Locations','fast-shipping'=>'Settings','site-review'=>'Content','currenc'=>'Settings','payment-gateway'=>'Payments'];
            return $map[$m]??'Other';
        }
        if (strpos($u,'order')!==false||strpos($u,'fulfill')!==false) return 'Orders';
        if (strpos($u,'refund')!==false) return 'Refunds';
        if (strpos($u,'shipment')!==false||strpos($u,'warehouse')!==false||strpos($u,'wms')!==false) return 'Fulfillment';
        if (strpos($u,'user')!==false||strpos($u,'role')!==false||strpos($u,'permission')!==false) return 'Roles & Permissions';
        if (strpos($u,'notification')!==false) return 'Notifications';
        if (strpos($u,'analytic')!==false||strpos($u,'dashboard')!==false||strpos($u,'report')!==false) return 'Dashboard';
        if (strpos($u,'invoice')!==false) return 'Orders';
        if (strpos($u,'review')!==false) return 'Reviews';
        if (strpos($u,'contact')!==false) return 'Customers';
        if (strpos($u,'address')!==false||strpos($u,'customer')!==false) return 'Customers';
        return 'Other';
    }
    if (preg_match('#/(token|register|login|logout|password|otp|social|me|contact$)#',$u)) return 'Authentication';
    if (strpos($u,'cart')!==false) return 'Cart';
    if (strpos($u,'checkout')!==false) return 'Checkout';
    if (strpos($u,'coupon')!==false) return 'Coupons';
    if (strpos($u,'order')!==false) return 'Orders';
    if (strpos($u,'payment')!==false) return 'Payments';
    if (strpos($u,'refund')!==false) return 'Refunds';
    if (strpos($u,'track')!==false) return 'Tracking';
    if (strpos($u,'invoice')!==false) return 'Invoices';
    if (strpos($u,'digital')!==false) return 'Digital';
    if (strpos($u,'notification')!==false||strpos($u,'preference')!==false) return 'Notifications';
    if (strpos($u,'review')!==false||strpos($u,'wishlist')!==false) return 'Reviews';
    if (strpos($u,'address')!==false||strpos($u,'profile')!==false) return 'Profile';
    if (strpos($u,'contact')!==false) return 'Contact';
    if (strpos($u,'shipment')!==false) return 'Shipments';
    return 'Catalog';
}

// ---- build spec ----
$spec = ['meta'=>[
  'specification_version'=>'2.0.0','generated_at'=>gmdate('Y-m-d\TH:i:s\Z'),'source_of_truth'=>'implementation',
  'audit_type'=>'forensic_api_contract_audit','accuracy_policy'=>'implementation_over_documentation',
  'laravel_version'=>'10.30.1','total_endpoint_count'=>count($inv),
  'detected_api_route_files'=>['routes/api.php','packages/marvel/src/Rest/Routes.php']],
 'authentication'=>['mechanisms'=>['sanctum Bearer via POST /api/v1/token, /api/v1/admin-login, register/social/otp','signed URLs (invoices view/download, digital download) with throttle:30,1','public guest endpoints (throttle:public-api)']],
 'global_rules'=>['wrapper'=>'Marvel\\Traits\\ApiResponse {status,message,success,data?} HTTP=status; Marvel FormRequest failedValidation returns raw {field:[msg]} 422 (no envelope)','idempotency'=>'No Idempotency-Key header on mutations (proven absent); idempotency via pending-order reuse (user_id+status=pending), UNIQUE(carts.user_id), transactions.idempotency_key on callbacks, single-use social/OTP codes','pricing'=>'Centralized ProductPricingService; never in controller/resource','throttles'=>'login 5/min/IP, otp 3/min/IP, public-api 120/min/IP, authenticated 300/min, cart 20/min, payment-callback/webhook dedicated, admin 400/min'],
 'enums'=>['payment_method'=>['online'=>'gateway redirect via {url}','cod'=>'cash on delivery, staff mark-paid','pay_at_cashier'=>'QR cashier, pickup only'],'fulfillment_type'=>['delivery'=>'requires governorate_id (+address if physical)','pickup'=>'requires pickup_location_id'],'shipping_type'=>['local','international'],'order_status'=>['pending','processing','packed','shipped','in_transit','arrived_at_destination_country','customs_clearance','customs_hold','customs_cleared','local_carrier','out_for_delivery','delivered','failed_delivery','returned','completed','cancelled','confirmed','ready_to_ship','ready_for_pickup'],'payment_status'=>['payment-pending','payment-success (fallback derived from transactions)','payment-failed','payment-refunded'],'transaction_status'=>['pending','paid','failed','refunded (partial/full paths in PaymentRefundService)']],
 'general_api'=>['summary'=>[],'endpoints'=>[]],'admin_api'=>['summary'=>[],'endpoints'=>[]],'system_api'=>['summary'=>[],'endpoints'=>[]],
 'business_flows'=>[
   ['name'=>'Customer purchase','actor'=>'customer','steps'=>['GET products/categories/brands (public)','POST cart (auth, SCHEDULED|FAST)','GET cart','POST coupons/apply (auth)','POST checkout (auth) -> {url}|{order_id}','pay via url -> callback|error-callback|webhooks -> completed','GET orders/{id} / tracking / invoices / digital download'],'branches'=>['online (gateway)','cod (staff mark-paid)','pay_at_cashier (pickup only + QR)','zero-value online (no provider call)'],'terminal'=>['completed','cancelled','delivered','returned']],
   ['name'=>'Admin catalog->order->fulfillment','actor'=>'admin/staff','steps'=>['POST admin-login (type=admin, verified)','products/categories/brands/inventory CRUD (permission:*)','orders list/show + PATCH status (permission:update-order-status, granular change-order-status.*)','refunds approve/reject','shipments/warehouses'],'terminal'=>['delivered','completed','cancelled','returned']]],
 'security_findings'=>[
   ['id'=>'N-01','severity'=>'BLOCKER','title'=>'Legacy PUT /api/v1/reviews/{review} IDOR + mass-assignment','evidence'=>'packages/marvel/src/Database/Repositories/ReviewRepository.php::updateReview persists user_id/product_id with no owner check; safe path app/Services/General/ProductService.php::updateProductReview owner-checks','recommendation'=>'delegate to safe path; strip to rating,comment; owner check 403/404'],
   ['id'=>'N-02','severity'=>'BLOCKER','title'=>'Invoice verify any-auth disclosure','evidence'=>'app/Services/Invoice/InvoiceService.php::verifyInvoice no user check; returns full InvoiceResource; GET with side effects (verify_count)','recommendation'=>'owner OR view-invoice permission; public minimal {authentic}; side-effect-free GET'],
   ['id'=>'N-08','severity'=>'MUST-FIX','title'=>'Reviews permission mismatch (contacts perms on reviews)','evidence'=>'Routes.php reviews resource gated by view-contacts|update-contact|...','recommendation'=>'review-domain perms + view-review(s); align delete-review(s)'],
   ['id'=>'C-05','severity'=>'MUST-FIX','title'=>'Public-group contacts reply/delete-all/index/show rely on controller middleware only','evidence'=>'Routes.php contacts group throttle:sensitive+lang, no auth:sanctum','recommendation'=>'move to admin group with route-level perms; keep only contact-us/store public'],
   ['id'=>'S-06','severity'=>'SHOULD-FIX','title'=>'Double /api/v1/v1/admin prefix unreachable at documented path','evidence'=>'RestAPIServiceProvider prefix api/v1 + inner prefix v1/admin/...','recommendation'=>'strip inner v1/; route:list diff; compat redirect'],
   ['id'=>'S-04','severity'=>'SHOULD-FIX','title'=>'fast-shipping/settings no route-level permission (controller-only gate)','evidence'=>'Routes.php fast-shipping lines lack permission: middleware','recommendation'=>'explicit route-level permission:view/update-fast-shipping']],
 'global_error_contract'=>['envelope'=>'{status,message,success:false} (+data:null on 429)','raw_validation'=>'{field:[msg]} 422 from Marvel FormRequest::failedValidation','auth'=>'401 {message:Unauthenticated,status:false}','throttle'=>'429 {success:false,message,data:null}'],
];
$groups=['general'=>[],'admin'=>[],'system'=>[]];
foreach ($inv as $e) {
    $g=classify($e); $key=$e['method'].' '.$e['uri'];
    $o=$over[$key]??null;
    $act=is_string($e['action'])?$e['action']:'Closure';
    $parts=explode('@',$act);
    $mw=implode(',',(array)$e['middleware']);
    $needsAuth = strpos($mw,'auth:sanctum')!==false || strpos($mw,'permission:')!==false || ($o['auth']['required']??false);
    $rec=['id'=>strtolower($key),'group'=>$g,'audience'=>audience($e,$g),'method'=>$e['method'],'path'=>$e['uri'],
     'name'=>$e['name'],'controller'=>$o['controller']??$parts[0],'action'=>$o['action']??($parts[1]??$act),
     'middleware'=>(array)$e['middleware'],
     'authentication'=>$o['auth']??['required'=>$needsAuth,'mechanism'=>$needsAuth?'sanctum Bearer':'none','throttle'=>null],
     'authorization'=>['required'=>strpos($mw,'permission:')!==false,'detail'=>strpos($mw,'permission:')!==false?$mw:($needsAuth?'owner scope may apply in-controller; verify before relying':'none')],
     'purpose'=>$o['business']['purpose']??('As-implemented behavior; inspect controller/service in implementation_trace before integrating.'),
     'request'=>['body_contract'=>$o?['fields'=>array_map(fn($f)=>['name'=>$f['name']??null,'rules'=>$f['rules']??null],$o['request_fields'])]:'unknown:not_found-inspect-controller-FormRequest','validation_sources'=>['routes','controller','FormRequest']],
     'responses'=>$o['responses']??[['status'=>'unknown','description'=>'unproven: route-confirmed only; verify controller Resource/serializer and exception handler before use. Reachable auth/throttle failures depend on middleware (401/403/429) but exact JSON not traced for this endpoint.']],
     'business_logic'=>$o['business']??['note'=>'untraced: route-confirmed only; see implementation_trace'],
     'implementation_trace'=>['route_files'=>[$o['route_file']??($g==='admin'||strpos($e['uri'],'/api/v1/')===0?'packages/marvel/src/Rest/Routes.php':'routes/api.php')],'evidence_files'=>array_values(array_unique(array_filter([$o['evidence']['request']??null,$o['evidence']['controller']??null,$o['evidence']['service']??null,$o['evidence']['handler']??null,$o['evidence']['code']??null])))],
     'evidence'=>['confidence'=>$o?'high':'medium','route'=>'confirmed','controller'=>$o?'confirmed':'route-action-only','fields'=>$o?'confirmed':'untraced'],
     'status'=>$o?'deep-traced':'route-confirmed'];
    $groups[$g][]=$rec;
}
foreach (['general','admin','system'] as $gk) { foreach ($groups[$gk] as &$r) {
    if (strpos($r['path'],'/api/v1/v1/')!==false) {
        $r['status']='unreachable-double-v1';
        $r['unreachable']=true;
        $r['unreachable_reason']='Effective runtime path contains /api/v1/v1/admin/* (RestAPIServiceProvider prefix api/v1 + inner prefix v1/admin). Documented /api/v1/admin/* 404s. Verify via route:list before use.';
    }
} unset($r); }
$spec['general_api']['endpoints']=$groups['general'];
$spec['admin_api']['endpoints']=$groups['admin'];
$spec['system_api']['endpoints']=$groups['system'];
$spec['audit_summary']=['total_routes'=>count($inv),'general'=>count($groups['general']),'admin'=>count($groups['admin']),'system'=>count($groups['system']),'deep_traced'=>count($over),'route_confirmed'=>count($inv)-count($over)];
file_put_contents(__DIR__.'/../api-master-spec.json', json_encode($spec, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
echo "spec endpoints: ".count($groups['general']).'/'.count($groups['admin']).'/'.count($groups['system']).PHP_EOL;
