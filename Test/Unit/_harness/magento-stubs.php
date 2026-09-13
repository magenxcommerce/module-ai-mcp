<?php
/**
 * Copyright © Magenx. All rights reserved.
 *
 * Stand-ins for the Magento (and PSR) types the unit suite touches.
 *
 * magento/framework is not on Packagist, so CI installs it only when the
 * caller passes a Marketplace key pair. Without one there is no vendor/ at
 * all, and PHPUnit cannot mock a type that has no definition — which is why
 * the suite errored rather than failed. These declarations give those names a
 * definition so the module's own logic can be exercised on a bare PHP.
 *
 * They are stand-ins, not a copy of Magento: only the members the suite
 * actually reaches are declared, and the signatures are kept deliberately
 * loose so a mock generated against them accepts whatever the real one would.
 * `bootstrap.php` beside it loads this file only when the real framework is
 * absent, so where Magento is installed the tests run against the genuine
 * types instead. Nothing outside the test suite loads it — it matches no
 * autoload rule, and .gitattributes keeps Test/ out of the distributed
 * package.
 *
 * @see \Magenx\AiMcp\Test\Unit\GeneratedFactory for the *Factory names, which
 *      Magento writes out at runtime rather than shipping.
 */
declare(strict_types=1);

// phpcs:disable

namespace {
    if (!function_exists('__')) {
        /**
         * Magento's translation helper, reduced to the numbered-placeholder
         * substitution the tests assert on. Returns a Phrase, as the real one
         * does, because production signatures declare that return type.
         */
        function __(...$arguments): \Magento\Framework\Phrase
        {
            $text = (string) array_shift($arguments);
            $values = (count($arguments) === 1 && is_array($arguments[0])) ? $arguments[0] : $arguments;

            $position = 1;
            foreach ($values as $value) {
                $rendered = is_float($value) ? (string) (0 + $value) : (string) $value;
                $text = str_replace('%' . $position++, $rendered, $text);
            }

            return new \Magento\Framework\Phrase($text, $values);
        }
    }
}

namespace Psr\Log {
    interface LoggerInterface {
        public function emergency($message, array $context = []);
        public function alert($message, array $context = []);
        public function critical($message, array $context = []);
        public function error($message, array $context = []);
        public function warning($message, array $context = []);
        public function notice($message, array $context = []);
        public function info($message, array $context = []);
        public function debug($message, array $context = []);
        public function log($level, $message, array $context = []);
    }
}

namespace Magento\Framework\Exception {
    class LocalizedException extends \Exception {
        public function __construct($phrase, ?\Exception $cause = null, $code = 0) {
            parent::__construct((string) $phrase, (int) $code, $cause);
        }
    }
    class NoSuchEntityException extends LocalizedException {}
    class AuthenticationException extends LocalizedException {}
    class AuthorizationException extends LocalizedException {}
    class InputException extends LocalizedException {}
    class CouldNotSaveException extends LocalizedException {}
    class CouldNotDeleteException extends LocalizedException {}
    class StateException extends LocalizedException {}
    class ValidatorException extends LocalizedException {}
}

namespace Magento\Framework {
    class Phrase {
        public function __construct(private $text = '', private array $arguments = []) {}
        public function getText() { return $this->text; }
        public function getArguments() { return $this->arguments; }
        public function render() { return (string) $this->text; }
        public function __toString(): string { return (string) $this->text; }
    }
}

namespace Magento\Framework\HTTP\PhpEnvironment {
    class RemoteAddress {
        public function getRemoteAddress($ipToLong = false) { return false; }
    }
}

namespace Magento\Framework\Stdlib\DateTime {
    class DateTime {
        public function gmtTimestamp($input = null) { return 0; }
        public function gmtDate($format = null, $input = null) { return ''; }
        public function date($format = null, $input = null) { return ''; }
    }
}

namespace Magento\Authorization\Model {
    interface UserContextInterface {
        const USER_TYPE_INTEGRATION = 1;
        const USER_TYPE_ADMIN = 2;
        const USER_TYPE_CUSTOMER = 3;
        const USER_TYPE_GUEST = 4;
        public function getUserId();
        public function getUserType();
    }
}

namespace Magento\Authorization\Model\Acl {
    class AclRetriever {
        public function getAllowedResourcesByUser($userType, $userId) { return []; }
        public function getAllowedResourcesByRole($roleId) { return []; }
    }
}

namespace Magento\Integration\Api {
    interface IntegrationServiceInterface {
        public function findByConsumerId($consumerId);
        public function get($integrationId);
        public function findByName($name);
    }
}

namespace Magento\Integration\Helper\Oauth {
    class Data {
        public function getAdminTokenLifetime() { return 4; }
        public function getCustomerTokenLifetime() { return 1; }
    }
}

namespace Magento\Sales\Api\Data {
    interface OrderAddressInterface {
        public function getFirstname(); public function getLastname(); public function getCompany();
        public function getStreet(); public function getCity(); public function getRegion();
        public function getPostcode(); public function getCountryId(); public function getTelephone();
    }
    interface OrderItemInterface {
        public function getItemId(); public function getSku(); public function getName();
        public function getPrice(); public function getRowTotal(); public function getParentItemId();
        public function getQtyOrdered(); public function getQtyInvoiced(); public function getQtyShipped();
        public function getQtyRefunded(); public function getQtyCanceled();
    }
    interface OrderInterface {
        public function getEntityId(); public function getIncrementId(); public function getState();
        public function getStatus(); public function getStoreId(); public function getStoreName();
        public function getCustomerEmail(); public function getCustomerFirstname(); public function getCustomerLastname();
        public function getCustomerId(); public function getCustomerIsGuest(); public function getCustomerNote();
        public function getOrderCurrencyCode(); public function getBaseCurrencyCode();
        public function getGrandTotal(); public function getTotalPaid(); public function getTotalRefunded();
        public function getTotalDue(); public function getCreatedAt(); public function getUpdatedAt();
        public function getSubtotal(); public function getSubtotalInvoiced(); public function getSubtotalRefunded();
        public function getTotalInvoiced(); public function getTotalCanceled(); public function getTotalOfflineRefunded();
        public function getTotalOnlineRefunded(); public function getTotalQtyOrdered();
        public function getPayment(); public function getBillingAddress(); public function getItems();
        public function getExtensionAttributes();
    }
}

namespace Magento\Customer\Api\Data {
    interface RegionExtensionInterface {}

    interface RegionInterface {
        public function getRegionCode(); public function setRegionCode($c);
        public function getRegion(); public function setRegion($r);
        public function getRegionId(); public function setRegionId($id);
    }
    class Region implements RegionInterface {
        private $code; private $region; private $id;
        public function getRegionCode() { return $this->code; }
        public function setRegionCode($c) { $this->code = $c; return $this; }
        public function getRegion() { return $this->region; }
        public function setRegion($r) { $this->region = $r; return $this; }
        public function getRegionId() { return $this->id; }
        public function setRegionId($id) { $this->id = $id; return $this; }
    }
    interface AddressInterface {
        public function getId(); public function setId($id);
        public function getCustomerId(); public function setCustomerId($id);
        public function getRegion(); public function setRegion(?RegionInterface $r = null);
        public function getRegionId(); public function setRegionId($id);
        public function getCountryId(); public function setCountryId($id);
        public function getStreet(); public function setStreet(array $s);
        public function getCompany(); public function setCompany($v);
        public function getTelephone(); public function setTelephone($v);
        public function getFax(); public function setFax($v);
        public function getPostcode(); public function setPostcode($v);
        public function getCity(); public function setCity($v);
        public function getFirstname(); public function setFirstname($v);
        public function getLastname(); public function setLastname($v);
        public function getMiddlename(); public function setMiddlename($v);
        public function getPrefix(); public function setPrefix($v);
        public function getSuffix(); public function setSuffix($v);
        public function getVatId(); public function setVatId($v);
        public function isDefaultShipping(); public function setIsDefaultShipping($v);
        public function isDefaultBilling(); public function setIsDefaultBilling($v);
    }
    class Address implements AddressInterface {
        public array $data = [];
        public function __construct(array $seed = []) { $this->data = $seed; }
        private function s(string $k, $v) { $this->data[$k] = $v; return $this; }
        public function getId() { return $this->data['id'] ?? null; }
        public function setId($id) { return $this->s('id', $id); }
        public function getCustomerId() { return $this->data['customer_id'] ?? null; }
        public function setCustomerId($id) { return $this->s('customer_id', $id); }
        public function getRegion() { return $this->data['region_obj'] ?? null; }
        public function setRegion(?RegionInterface $r = null) { return $this->s('region_obj', $r); }
        public function getRegionId() { return $this->data['region_id'] ?? null; }
        public function setRegionId($id) { return $this->s('region_id', $id); }
        public function getCountryId() { return $this->data['country_id'] ?? null; }
        public function setCountryId($id) { return $this->s('country_id', $id); }
        public function getStreet() { return $this->data['street'] ?? null; }
        public function setStreet(array $s) { return $this->s('street', $s); }
        public function getCompany() { return $this->data['company'] ?? null; }
        public function setCompany($v) { return $this->s('company', $v); }
        public function getTelephone() { return $this->data['telephone'] ?? null; }
        public function setTelephone($v) { return $this->s('telephone', $v); }
        public function getFax() { return $this->data['fax'] ?? null; }
        public function setFax($v) { return $this->s('fax', $v); }
        public function getPostcode() { return $this->data['postcode'] ?? null; }
        public function setPostcode($v) { return $this->s('postcode', $v); }
        public function getCity() { return $this->data['city'] ?? null; }
        public function setCity($v) { return $this->s('city', $v); }
        public function getFirstname() { return $this->data['firstname'] ?? null; }
        public function setFirstname($v) { return $this->s('firstname', $v); }
        public function getLastname() { return $this->data['lastname'] ?? null; }
        public function setLastname($v) { return $this->s('lastname', $v); }
        public function getMiddlename() { return $this->data['middlename'] ?? null; }
        public function setMiddlename($v) { return $this->s('middlename', $v); }
        public function getPrefix() { return $this->data['prefix'] ?? null; }
        public function setPrefix($v) { return $this->s('prefix', $v); }
        public function getSuffix() { return $this->data['suffix'] ?? null; }
        public function setSuffix($v) { return $this->s('suffix', $v); }
        public function getVatId() { return $this->data['vat_id'] ?? null; }
        public function setVatId($v) { return $this->s('vat_id', $v); }
        public function isDefaultShipping() { return $this->data['is_default_shipping'] ?? null; }
        public function setIsDefaultShipping($v) { return $this->s('is_default_shipping', $v); }
        public function isDefaultBilling() { return $this->data['is_default_billing'] ?? null; }
        public function setIsDefaultBilling($v) { return $this->s('is_default_billing', $v); }
    }
    interface CustomerInterface {
        public function getId(); public function setId($id);
        public function getGroupId(); public function setGroupId($v);
        public function getStoreId(); public function setStoreId($v);
        public function getGender(); public function setGender($v);
        public function getFirstname(); public function setFirstname($v);
        public function getLastname(); public function setLastname($v);
        public function getMiddlename(); public function setMiddlename($v);
        public function getPrefix(); public function setPrefix($v);
        public function getSuffix(); public function setSuffix($v);
        public function getDob(); public function setDob($v);
        public function getTaxvat(); public function setTaxvat($v);
        public function getDisableAutoGroupChange(); public function setDisableAutoGroupChange($v);
        public function setCustomAttribute($code, $value);
    }
    class Customer implements CustomerInterface {
        public array $data = []; public array $custom = [];
        private function s(string $k, $v) { $this->data[$k] = $v; return $this; }
        public function getId() { return $this->data['id'] ?? null; }
        public function setId($id) { return $this->s('id', $id); }
        public function getGroupId() { return $this->data['group_id'] ?? null; }
        public function setGroupId($v) { return $this->s('group_id', $v); }
        public function getStoreId() { return $this->data['store_id'] ?? null; }
        public function setStoreId($v) { return $this->s('store_id', $v); }
        public function getGender() { return $this->data['gender'] ?? null; }
        public function setGender($v) { return $this->s('gender', $v); }
        public function getFirstname() { return $this->data['firstname'] ?? null; }
        public function setFirstname($v) { return $this->s('firstname', $v); }
        public function getLastname() { return $this->data['lastname'] ?? null; }
        public function setLastname($v) { return $this->s('lastname', $v); }
        public function getMiddlename() { return $this->data['middlename'] ?? null; }
        public function setMiddlename($v) { return $this->s('middlename', $v); }
        public function getPrefix() { return $this->data['prefix'] ?? null; }
        public function setPrefix($v) { return $this->s('prefix', $v); }
        public function getSuffix() { return $this->data['suffix'] ?? null; }
        public function setSuffix($v) { return $this->s('suffix', $v); }
        public function getDob() { return $this->data['dob'] ?? null; }
        public function setDob($v) { return $this->s('dob', $v); }
        public function getTaxvat() { return $this->data['taxvat'] ?? null; }
        public function setTaxvat($v) { return $this->s('taxvat', $v); }
        public function getDisableAutoGroupChange() { return $this->data['dagc'] ?? null; }
        public function setDisableAutoGroupChange($v) { return $this->s('dagc', $v); }
        public function setCustomAttribute($code, $value) { $this->custom[$code] = $value; return $this; }
    }
}

namespace Magento\Catalog\Api\Data {
    interface TierPriceInterface {
        const PRICE_TYPE_FIXED = 'fixed';
        const PRICE_TYPE_DISCOUNT = 'discount';
        public function setPrice($p); public function getPrice();
        public function setPriceType($t); public function getPriceType();
        public function setWebsiteId($w); public function getWebsiteId();
        public function setSku($s); public function getSku();
        public function setCustomerGroup($g); public function getCustomerGroup();
        public function setQuantity($q); public function getQuantity();
    }
    class TierPrice implements TierPriceInterface {
        public array $d = [];
        public function setPrice($p) { $this->d['price'] = $p; return $this; }
        public function getPrice() { return $this->d['price'] ?? null; }
        public function setPriceType($t) { $this->d['price_type'] = $t; return $this; }
        public function getPriceType() { return $this->d['price_type'] ?? null; }
        public function setWebsiteId($w) { $this->d['website_id'] = $w; return $this; }
        public function getWebsiteId() { return $this->d['website_id'] ?? null; }
        public function setSku($s) { $this->d['sku'] = $s; return $this; }
        public function getSku() { return $this->d['sku'] ?? null; }
        public function setCustomerGroup($g) { $this->d['customer_group'] = $g; return $this; }
        public function getCustomerGroup() { return $this->d['customer_group'] ?? null; }
        public function setQuantity($q) { $this->d['quantity'] = $q; return $this; }
        public function getQuantity() { return $this->d['quantity'] ?? null; }
    }
    interface PriceUpdateResultInterface {
        public function getMessage(); public function setMessage($m);
        public function getParameters(); public function setParameters(array $p);
    }
    class PriceUpdateResult implements PriceUpdateResultInterface {
        private $m = ''; private array $p = [];
        public function __construct(string $m = '', array $p = []) { $this->m = $m; $this->p = $p; }
        public function getMessage() { return $this->m; }
        public function setMessage($m) { $this->m = $m; return $this; }
        public function getParameters() { return $this->p; }
        public function setParameters(array $p) { $this->p = $p; return $this; }
    }
    interface ProductAttributeInterface {
        const ENTITY_TYPE_CODE = 'catalog_product';
        public function setDefaultFrontendLabel($v); public function setNote($v); public function setDefaultValue($v);
        public function setScope($v);
        public function setIsRequired($v); public function setIsUnique($v); public function setIsSearchable($v);
        public function setIsVisibleInAdvancedSearch($v); public function setIsComparable($v);
        public function setIsFilterable($v); public function setIsFilterableInSearch($v);
        public function setIsVisibleOnFront($v); public function setUsedInProductListing($v);
        public function setUsedForSortBy($v); public function setIsUsedForPromoRules($v);
        public function setIsUsedInGrid($v); public function setIsVisibleInGrid($v); public function setIsFilterableInGrid($v);
    }
    class ProductAttribute implements ProductAttributeInterface {
        public array $d = [];
        private function s($k, $v) { $this->d[$k] = $v; return $this; }
        public function setDefaultFrontendLabel($v) { return $this->s('default_frontend_label', $v); }
        public function setNote($v) { return $this->s('note', $v); }
        public function setDefaultValue($v) { return $this->s('default_value', $v); }
        public function setScope($v) { return $this->s('scope', $v); }
        public function setIsRequired($v) { return $this->s('is_required', $v); }
        public function setIsUnique($v) { return $this->s('is_unique', $v); }
        public function setIsSearchable($v) { return $this->s('is_searchable', $v); }
        public function setIsVisibleInAdvancedSearch($v) { return $this->s('is_visible_in_advanced_search', $v); }
        public function setIsComparable($v) { return $this->s('is_comparable', $v); }
        public function setIsFilterable($v) { return $this->s('is_filterable', $v); }
        public function setIsFilterableInSearch($v) { return $this->s('is_filterable_in_search', $v); }
        public function setIsVisibleOnFront($v) { return $this->s('is_visible_on_front', $v); }
        public function setUsedInProductListing($v) { return $this->s('used_in_product_listing', $v); }
        public function setUsedForSortBy($v) { return $this->s('used_for_sort_by', $v); }
        public function setIsUsedForPromoRules($v) { return $this->s('is_used_for_promo_rules', $v); }
        public function setIsUsedInGrid($v) { return $this->s('is_used_in_grid', $v); }
        public function setIsVisibleInGrid($v) { return $this->s('is_visible_in_grid', $v); }
        public function setIsFilterableInGrid($v) { return $this->s('is_filterable_in_grid', $v); }
    }
    interface ProductAttributeMediaGalleryEntryInterface {
        public function getId(); public function setId($id);
        public function getMediaType(); public function setMediaType($v);
        public function getLabel(); public function setLabel($v);
        public function getPosition(); public function setPosition($v);
        public function isDisabled(); public function setDisabled($v);
        public function getTypes(); public function setTypes(?array $types = null);
        public function getFile(); public function setFile($v);
        public function getContent(); public function setContent($v);
    }
    interface ProductLinkInterface {
        public function getSku(); public function setSku($v);
        public function getLinkType(); public function setLinkType($v);
        public function getLinkedProductSku(); public function setLinkedProductSku($v);
        public function getLinkedProductType(); public function setLinkedProductType($v);
        public function getPosition(); public function setPosition($v);
    }
    interface ProductLinkTypeInterface {
        public function getCode(); public function getName();
    }
}

namespace Magento\Catalog\Api {
    interface ProductAttributeMediaGalleryManagementInterface {
        public function create($sku, \Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface $entry);
        public function update($sku, \Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface $entry);
        public function remove($sku, $entryId);
        public function get($sku, $entryId);
        public function getList($sku);
    }
    interface ProductLinkManagementInterface {
        public function getLinkedItemsByType($sku, $type);
        public function setProductLinks($sku, array $items);
    }
    interface ProductLinkTypeListInterface {
        public function getItems();
        public function getItemAttributes($type);
    }
}

namespace Magento\Cms\Api\Data {
    interface PageInterface {
        public function getId(); public function setId($id);
        public function getIdentifier(); public function setIdentifier($v);
        public function getTitle(); public function setTitle($v);
        public function getPageLayout(); public function setPageLayout($v);
        public function getMetaTitle(); public function setMetaTitle($v);
        public function getMetaKeywords(); public function setMetaKeywords($v);
        public function getMetaDescription(); public function setMetaDescription($v);
        public function getContentHeading(); public function setContentHeading($v);
        public function getContent(); public function setContent($v);
        public function getSortOrder(); public function setSortOrder($v);
        public function isActive(); public function setIsActive($v);
    }
    class Page implements PageInterface {
        public array $d = [];
        private function s($k, $v) { $this->d[$k] = $v; return $this; }
        public function getId() { return $this->d['id'] ?? null; }
        public function setId($id) { return $this->s('id', $id); }
        public function getIdentifier() { return $this->d['identifier'] ?? null; }
        public function setIdentifier($v) { return $this->s('identifier', $v); }
        public function getTitle() { return $this->d['title'] ?? null; }
        public function setTitle($v) { return $this->s('title', $v); }
        public function getPageLayout() { return $this->d['page_layout'] ?? null; }
        public function setPageLayout($v) { return $this->s('page_layout', $v); }
        public function getMetaTitle() { return $this->d['meta_title'] ?? null; }
        public function setMetaTitle($v) { return $this->s('meta_title', $v); }
        public function getMetaKeywords() { return $this->d['meta_keywords'] ?? null; }
        public function setMetaKeywords($v) { return $this->s('meta_keywords', $v); }
        public function getMetaDescription() { return $this->d['meta_description'] ?? null; }
        public function setMetaDescription($v) { return $this->s('meta_description', $v); }
        public function getContentHeading() { return $this->d['content_heading'] ?? null; }
        public function setContentHeading($v) { return $this->s('content_heading', $v); }
        public function getContent() { return $this->d['content'] ?? null; }
        public function setContent($v) { return $this->s('content', $v); }
        public function getSortOrder() { return $this->d['sort_order'] ?? null; }
        public function setSortOrder($v) { return $this->s('sort_order', $v); }
        public function isActive() { return $this->d['is_active'] ?? null; }
        public function setIsActive($v) { return $this->s('is_active', $v); }
    }
}

namespace Magento\Framework\Api\Data {
    interface ImageContentInterface {
        public function getBase64EncodedData(); public function setBase64EncodedData($d);
        public function getType(); public function setType($t);
        public function getName(); public function setName($n);
    }
}

namespace Magento\Framework\App\Config {
    interface ScopeConfigInterface {
        public function getValue($path, $scopeType = 'default', $scopeCode = null);
        public function isSetFlag($path, $scopeType = 'default', $scopeCode = null);
    }
}

namespace Magento\Framework\Serialize\Serializer {
    class Json {
        public function serialize($data) { return json_encode($data); }
        public function unserialize($string) { return json_decode($string, true); }
    }
}
