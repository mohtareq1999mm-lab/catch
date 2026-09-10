<?php


namespace Marvel\Enums;

use BenSampo\Enum\Enum;

/**
 * Class RoleType
 * @package App\Enums
 */
final class Permission extends Enum
{

    public const EDITOR = 'editor';
    public const STORE_OWNER = 'store_owner';
    public const STAFF = 'staff';
    public const CUSTOMER = 'customer';



    public const VIEW_SLIDER = 'view-slider';
    public const CREATE_SLIDER = 'create-slider';
    public const UPDATE_SLIDER = 'update-slider';
    public const DELETE_SLIDER = 'delete-slider';
    public const VIEW_NOTIFICATTIONS = 'view-notifications';
    public const MANAGE_NOTIFICATTIONS = 'manage-notifications';

    public const VIEW_ACTIVITY_LOG = 'view-activity-log';

    public const VIEW_NOTIFICATIONS = 'view-notifications';
    public const MANAGE_NOTIFICATIONS = 'manage-notifications';


    // 🔓 Public
    public const VIEW_PRODUCTS = 'view-products';
    public const VIEW_PRODUCT = 'view-product';
    public const VIEW_CATEGORIES = 'view-categories';
    public const VIEW_CATEGORY = 'view-category';
    public const VIEW_SHOPS = 'view-shops';
    public const VIEW_SHOP = 'view-shop';
    public const VIEW_TYPES = 'view-types';
    public const VIEW_TYPE = 'view-type';
    public const VIEW_TAGS = 'view-tags';
    public const CREATE_TAGS= 'create-tags';
    public const UPDATE_TAGS= 'update-tags';
    public const DELETE_TAGS= 'delete-tags';
    public const VIEW_TAG = 'view-tag';
    public const VIEW_AUTHORS = 'view-authors';
    public const VIEW_AUTHOR = 'view-author';
    public const VIEW_MANUFACTURERS = 'view-manufacturers';
    public const VIEW_MANUFACTURER = 'view-manufacturer';
    public const VIEW_BRANDS = 'view-brands';
    public const VIEW_BRAND = 'view-brand';
    public const VIEW_CONTACTS = 'view-contacts';
    public const VIEW_COUPONS = 'view-coupons';
    public const VERIFY_COUPON = 'verify-coupon';
    public const VIEW_CMS_PAGES = 'view-cms-pages';
    public const VIEW_CMS_PAGE = 'view-cms-page';
    public const VIEW_FAQS = 'view-faqs';
    public const VIEW_SETTINGS = 'view-settings';

    public const CREATE_ROLES = 'create-roles';
    public const UPDATE_ROLES = 'update-roles';
    public const DELETE_ROLES = 'delete-roles';
    public const VIEW_ROLES = 'view-roles';
    public const VIEW_ROLE = 'view-role';

    public const ASSIGN_ROLE = 'assign-role';
    public const REMOVE_ROLE = 'remove-role';

    public const VIEW_FlASH_SALE = "view-flash-sale";
    public const CREATE_FlASH_SALE = "create-flash-sale";
    public const UPDATE_FlASH_SALE = "update-flash-sale";
    public const DELETE_FlASH_SALE = "delete-flash-sale";

    public const VIEW_BANNERS = "view-banners";
    public const CREATE_BANNERS = "create-banners";
    public const UPDATE_BANNERS = "update-banners";
    public const DELETE_BANNERS = "delete-banners";
    public const EDIT_USER = 'edit-user';

    public const APPROVE_REVIEWS = 'approve-reviews';
    public const DELETE_REVIEWS = 'delete-reviews';

    public const VIEW_SITE_REVIEWS = 'view-site-reviews';
    public const APPROVE_SITE_REVIEWS = 'approve-site-reviews';
    public const REJECT_SITE_REVIEWS = 'reject-site-reviews';

    // 💱 Currencies
    public const VIEW_CURRENCIES = 'view-currencies';
    public const CREATE_CURRENCY = 'create-currency';
    public const UPDATE_CURRENCY = 'update-currency';
    public const DELETE_CURRENCY = 'delete-currency';
    public const VIEW_EXCHANGE_RATES = 'view-exchange-rates';
    public const CREATE_EXCHANGE_RATE = 'create-exchange-rate';
    public const UPDATE_EXCHANGE_RATE = 'update-exchange-rate';
    public const DELETE_EXCHANGE_RATE = 'delete-exchange-rate';
    public const SET_BASE_CURRENCY = 'set-base-currency';
    public const SET_CATALOG_CURRENCY = 'set-catalog-currency';

    public const VIEW_CITY = 'view-city';
    public const CREATE_CITY = 'create-city';
    public const UPDATE_CITY = 'update-city';
    public const DELETE_CITY = 'delete-city';

    public const VIEW_GOVERNORATE = 'view-governorate';
    public const CREATE_GOVERNORATE = 'create-governorate';
    public const UPDATE_GOVERNORATE = 'update-governorate';
    public const DELETE_GOVERNORATE = 'delete-governorate';

    public const VIEW_FAST_SHIPPING = 'view-fast-shipping';
    public const UPDATE_FAST_SHIPPING = 'update-fast-shipping';

    public const VIEW_COUNTRY = 'view-country';
    public const CREATE_COUNTRY = 'create-country';
    public const UPDATE_COUNTRY = 'update-country';
    public const DELETE_COUNTRY = 'delete-country';

    // 👤 Customer
    public const VIEW_PROFILE = 'view-profile';
    public const UPDATE_PROFILE = 'update-profile';
    public const CHANGE_PASSWORD = 'change-password';
    public const VIEW_ORDERS = 'view-orders';
    public const VIEW_ORDER = 'view-order';
    public const CREATE_ORDER = 'create-order';
    public const TRACK_ORDER = 'track-order';
    public const CREATE_REVIEW = 'create-review';
    public const UPDATE_REVIEW = 'update-review';
    public const VIEW_WISHLIST = 'view-wishlist';
    public const ADD_WISHLIST = 'add-wishlist';
    public const REMOVE_WISHLIST = 'remove-wishlist';
    public const TOGGLE_WISHLIST = 'toggle-wishlist';
    public const VIEW_REFUNDS = 'view-refunds';
    public const CREATE_REFUND = 'create-refund';
    public const VIEW_CONVERSATIONS = 'view-conversations';
    public const CREATE_CONVERSATION = 'create-conversation';
    public const ASK_QUESTION = 'ask-question';
    public const VIEW_MY_QUESTIONS = 'view-my-questions';
    public const FOLLOW_SHOP = 'follow-shop';
    public const VIEW_FOLLOWED_SHOPS = 'view-followed-shops';

    // 👷 Staff / Store Owner (shared)
    public const CREATE_PRODUCT = 'create-product';
    public const UPDATE_PRODUCT = 'update-product';
    public const DELETE_PRODUCT = 'delete-product';
    public const CREATE_ATTRIBUTE = 'create-attribute';
    public const UPDATE_ATTRIBUTE = 'update-attribute';
    public const DELETE_ATTRIBUTE = 'delete-attribute';
    public const UPDATE_ORDER_STATUS = 'update-order-status';
    public const ANSWER_QUESTION = 'answer-question';
    public const CREATE_AUTHOR = 'create-author';


    public const CREATE_MANUFACTURER = 'create-manufacturer';
    public const UPDATE_CONTACT = 'update-contact';
    public const DELETE_CONTACT = 'delete-contact';
    public const DELETE_READ_CONTACTS = 'delete-read-contacts';
    public const VIEW_ANALYTICS = 'view-analytics';
    public const EXPORT_ANALYTICS = 'export-analytics';
    public const VIEW_LOW_STOCK_PRODUCTS = 'view-low-stock-products';
    public const VIEW_DRAFT_PRODUCTS = 'view-draft-products';
    public const UPDATE_COUPON = 'update-coupon';
    public const CREATE_STORE_NOTICE = 'create-store-notice';
    public const UPDATE_STORE_NOTICE = 'update-store-notice';
    public const DELETE_STORE_NOTICE = 'delete-store-notice';
    public const CREATE_FAQ = 'create-faq';
    public const UPDATE_FAQ = 'update-faq';
    public const DELETE_FAQ = 'delete-faq';

    // 🏪 Store Owner
    public const CREATE_SHOP = 'create-shop';
    public const UPDATE_SHOP = 'update-shop';
    public const DELETE_SHOP = 'delete-shop';
    public const VIEW_MY_SHOPS = 'view-my-shops';
    public const ADD_STAFF = 'add-staff';
    public const REMOVE_STAFF = 'remove-staff';
    public const VIEW_STAFFS = 'view-staffs';
    public const TRANSFER_SHOP_OWNERSHIP = 'transfer-shop-ownership';
    public const VIEW_WITHDRAWS = 'view-withdraws';
    public const CREATE_WITHDRAW = 'create-withdraw';
    public const CREATE_COUPON = 'create-coupon';
    public const DELETE_COUPON = 'delete-coupon';

    public const VIEW_PROMOTION = 'view-promotion';
    public const UPDATE_PROMOTION = 'update-promotion';
    public const DELETE_PROMOTION = 'delete-promotion';
    public const CREATE_PROMOTION = 'create-promotion';

    // ✏️ Editor
    public const CREATE_CMS_PAGE = 'create-cms-page';
    public const UPDATE_CMS_PAGE = 'update-cms-page';
    public const DELETE_CMS_PAGE = 'delete-cms-page';
    public const SAVE_PUCK_PAGE = 'save-puck-page';

    // 🗂 Content Pages
    public const VIEW_CONTENT_PAGES = 'view-content-pages';
    public const CREATE_CONTENT_PAGES = 'create-content-pages';
    public const UPDATE_CONTENT_PAGES = 'update-content-pages';
    public const DELETE_CONTENT_PAGES = 'delete-content-pages';

    // 📦 Sections
    public const VIEW_SECTIONS = 'view-sections';
    public const CREATE_SECTIONS = 'create-sections';
    public const UPDATE_SECTIONS = 'update-sections';
    public const DELETE_SECTIONS = 'delete-sections';

    // 🏷 Section Types
    public const VIEW_SECTION_TYPES = 'view-section-types';
    public const CREATE_SECTION_TYPES = 'create-section-types';
    public const UPDATE_SECTION_TYPES = 'update-section-types';
    public const DELETE_SECTION_TYPES = 'delete-section-types';

    // 📄 Static Pages
    public const VIEW_STATIC_PAGES = 'view-static-pages';
    public const UPDATE_STATIC_PAGES = 'update-static-pages';
    public const CREATE_STATIC_SECTIONS = 'create-static-sections';
    public const UPDATE_STATIC_SECTIONS = 'update-static-sections';
    public const DELETE_STATIC_SECTIONS = 'delete-static-sections';

    // 🔐 Super Admin
    public const VIEW_ADMINS = 'view-admins';
    public const VIEW_VENDORS = 'view-vendors';
    public const VIEW_CUSTOMERS = 'view-customers';
    public const VIEW_USERS = 'view-users';
    public const CREATE_USER = 'create-user';
    public const UPDATE_USER = 'update-user';
    public const DELETE_USER = 'delete-user';
    public const BAN_USER = 'ban-user';
    public const ACTIVATE_USER = 'activate-user';
    public const UPDATE_USER_ACTIVATION = 'update-user-activation';
    public const RESTORE_USER = 'restore-user';
    public const MAKE_ADMIN = 'make-admin';
    public const CREATE_TYPE = 'create-type';
    public const UPDATE_TYPE = 'update-type';
    public const DELETE_TYPE = 'delete-type';
    public const CREATE_CATEGORY = 'create-category';
    public const UPDATE_CATEGORY = 'update-category';
    public const DELETE_CATEGORY = 'delete-category';
    public const IMPORT_CATEGORY = 'import-category';
    public const EXPORT_CATEGORY = 'export-category';
    public const IMPORT_PRODUCT = 'import-product';
    public const EXPORT_PRODUCT = 'export-product';
    public const IMPORT_BRAND = 'import-brand';
    public const EXPORT_BRAND = 'export-brand';
    public const CREATE_BRAND = 'create-brand';
    public const UPDATE_BRAND = 'update-brand';
    public const DELETE_BRAND = 'delete-brand';
    public const CREATE_TAG = 'create-tag';
    public const UPDATE_TAG = 'update-tag';
    public const DELETE_TAG = 'delete-tag';
    public const UPDATE_AUTHOR = 'update-author';
    public const DELETE_AUTHOR = 'delete-author';
    public const UPDATE_MANUFACTURER = 'update-manufacturer';
    public const DELETE_MANUFACTURER = 'delete-manufacturer';
    public const APPROVE_WITHDRAW = 'approve-withdraw';
    public const APPROVE_SHOP = 'approve-shop';
    public const DISAPPROVE_SHOP = 'disapprove-shop';
    public const VIEW_NEW_SHOPS = 'view-new-shops';
    public const UPDATE_SETTINGS = 'update-settings';
    public const DELETE_REVIEW = 'delete-review';
    public const DELETE_QUESTION = 'delete-question';
    public const CREATE_SHIPPING = 'create-shipping';
    public const ADD_POINTS = 'add-points';
    public const APPROVE_COUPON = 'approve-coupon';
    public const DISAPPROVE_COUPON = 'disapprove-coupon';
    public const VIEW_ABUSIVE_REPORTS = 'view-abusive-reports';
    public const ACCEPT_ABUSIVE_REPORT = 'accept-abusive-report';
    public const REJECT_ABUSIVE_REPORT = 'reject-abusive-report';
    public const VIEW_ATTRIBUTES = 'view-attributes';
    public const MANAGE_SHIPPING_PRICES = 'manage-shipping-prices';

    // 📍 Pickup Locations
    public const VIEW_PICKUP_LOCATIONS = 'view-pickup-locations';
    public const CREATE_PICKUP_LOCATION = 'create-pickup-location';
    public const UPDATE_PICKUP_LOCATION = 'update-pickup-location';
    public const DELETE_PICKUP_LOCATION = 'delete-pickup-location';

    // 🎟 Coupon Assignments
    public const VIEW_COUPON_ASSIGNMENTS = 'view-coupon-assignments';
    public const CREATE_COUPON_ASSIGNMENT = 'create-coupon-assignment';
    public const UPDATE_COUPON_ASSIGNMENT = 'update-coupon-assignment';
    public const DELETE_COUPON_ASSIGNMENT = 'delete-coupon-assignment';

    // 🧾 Invoices
    public const VIEW_INVOICES = 'view-invoices';
    public const VIEW_INVOICE = 'view-invoice';
    public const VIEW_INVOICE_DOWNLOAD = 'view-invoice-download';
    public const REGENERATE_INVOICE = 'regenerate-invoice';
    public const CORRECT_INVOICE = 'correct-invoice';
    public const CANCEL_INVOICE = 'cancel-invoice';
    public const ISSUE_DEBIT_NOTE = 'issue-debit-note';

    // 📦 Shipments
    public const VIEW_SHIPMENTS = 'view-shipments';
    public const VIEW_SHIPMENT = 'view-shipment';
    public const CREATE_SHIPMENT = 'create-shipment';
    public const UPDATE_SHIPMENT = 'update-shipment';

    // 🔑 Digital licenses (Workstream 5, decision A4)
    public const MANAGE_DIGITAL_LICENSES = 'manage-digital-licenses';

    // 🛂 Digital entitlement access management (Workstream 6, decision A4)
    public const MANAGE_DIGITAL_ACCESS = 'manage-digital-access';
}