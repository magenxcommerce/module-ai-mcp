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

namespace Magento\Framework\Exception\State {
    class InvalidTransitionException extends \Magento\Framework\Exception\LocalizedException {}
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
    interface TimezoneInterface {
        public function getConfigTimezone($scopeType = null, $scopeCode = null);
        public function scopeDate($scope = null, $date = null, $includeTime = false);
        public function date($date = null, $locale = null, $useTimezone = true, $includeTime = true);
    }
}

namespace Magento\Store\Model {
    class ScopeInterface {
        public const SCOPE_STORE = 'store';
        public const SCOPE_STORES = 'stores';
        public const SCOPE_WEBSITE = 'website';
    }
}

namespace Magento\Framework\DB {
    // Only the builder calls the aggregator makes. Every one returns $this, as
    // the real Select does, so a mock can record the calls in order; what the
    // rendered SQL looks like is not something this stub can speak to.
    class Select {
        public function from($name, $cols = '*', $schema = null) { return $this; }
        public function joinLeft($name, $cond, $cols = '*', $schema = null) { return $this; }
        public function joinInner($name, $cond, $cols = '*', $schema = null) { return $this; }
        public function columns($cols = '*', $correlationName = null) { return $this; }
        public function where($cond, $value = null, $type = null) { return $this; }
        public function group($spec) { return $this; }
        public function order($spec) { return $this; }
        public function limit($count = null, $offset = null) { return $this; }
        public function reset($part = null) { return $this; }
    }
}

namespace Magento\Framework\DB\Adapter {
    interface AdapterInterface {
        public function select();
        public function fetchAll($sql, $bind = [], $fetchMode = null);
        public function fetchRow($sql, $bind = [], $fetchMode = null);
        public function fetchOne($sql, $bind = []);
        public function quoteInto($text, $value, $type = null, $count = null);
        public function quote($value, $type = null);
    }
}

namespace Magento\Framework\App {
    class ResourceConnection {
        public function getConnection($resourceName = 'default') { return null; }
        public function getTableName($modelEntity, $connectionName = 'default') { return $modelEntity; }
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
        public function getEntityId(); public function getEmail(); public function getRegionId();
        public function getFirstname(); public function getLastname(); public function getCompany();
        public function getStreet(); public function getCity(); public function getRegion();
        public function getPostcode(); public function getCountryId(); public function getTelephone();
        public function setFirstname($v); public function setLastname($v); public function setCompany($v);
        public function setStreet($v); public function setCity($v); public function setRegion($v);
        public function setRegionId($v); public function setPostcode($v); public function setCountryId($v);
        public function setTelephone($v); public function setEmail($v);
    }
    interface CommentInterface {
        public function getComment(); public function getCreatedAt();
        public function getIsCustomerNotified(); public function getIsVisibleOnFront();
    }
    interface InvoiceItemInterface {
        public function getOrderItemId(); public function getSku(); public function getName();
        public function getQty(); public function getPrice(); public function getRowTotal();
    }
    interface InvoiceInterface {
        public function getEntityId(); public function getIncrementId(); public function getOrderId();
        public function getStoreId(); public function getState(); public function getGrandTotal();
        public function getBaseGrandTotal(); public function getTotalQty(); public function getBaseTotalRefunded();
        public function getTransactionId(); public function getCreatedAt();
        public function getOrderCurrencyCode(); public function getBaseCurrencyCode();
        public function getSubtotal(); public function getShippingAmount(); public function getTaxAmount();
        public function getDiscountAmount(); public function getShippingTaxAmount();
        public function getItems(); public function getComments();
    }
    interface ShipmentTrackInterface {
        public function getEntityId(); public function getParentId(); public function getOrderId();
        public function getTrackNumber(); public function getCarrierCode(); public function getTitle();
        public function setParentId($v); public function setOrderId($v); public function setTrackNumber($v);
        public function setCarrierCode($v); public function setTitle($v);
    }
    interface ShipmentItemInterface {
        public function getOrderItemId(); public function getSku(); public function getName();
        public function getQty(); public function getWeight();
    }
    interface ShipmentInterface {
        public function getEntityId(); public function getIncrementId(); public function getOrderId();
        public function getStoreId(); public function getTotalQty(); public function getTotalWeight();
        public function getShippingLabel(); public function getShippingAddressId(); public function getTracks();
        public function getItems(); public function getComments(); public function getCreatedAt();
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

namespace Magento\Sales\Api {
    interface OrderRepositoryInterface {
        public function get($id); public function getList($searchCriteria);
        public function save(\Magento\Sales\Api\Data\OrderInterface $entity);
    }
    interface OrderAddressRepositoryInterface {
        public function get($id); public function save(\Magento\Sales\Api\Data\OrderAddressInterface $entity);
    }
    interface ShipmentTrackRepositoryInterface {
        public function get($id); public function getList($searchCriteria);
        public function save(\Magento\Sales\Api\Data\ShipmentTrackInterface $entity);
        public function delete(\Magento\Sales\Api\Data\ShipmentTrackInterface $entity);
        public function deleteById($id);
    }
    interface InvoiceRepositoryInterface {
        public function get($id); public function getList($searchCriteria);
    }
    interface ShipmentRepositoryInterface {
        public function get($id); public function getList($searchCriteria);
    }
    interface CreditmemoRepositoryInterface {
        public function get($id); public function getList($searchCriteria);
    }
    interface InvoiceManagementInterface {
        public function setCapture($id); public function setVoid($id); public function notify($id);
    }
    interface OrderManagementInterface {
        public function notify($id);
    }
}

namespace Magento\Shipping\Model {
    class Config {
        public function getAllCarriers($store = null) { return []; }
    }
}

namespace Magento\Framework\Api {
    interface SearchCriteriaInterface {}
    interface SearchResultsInterface {
        public function getItems(); public function getTotalCount();
    }
    class SearchCriteriaBuilder {
        public function addFilter($field, $value, $conditionType = 'eq') { return $this; }
        public function setPageSize($size) { return $this; }
        public function setCurrentPage($page) { return $this; }
        public function addSortOrder($sortOrder) { return $this; }
        public function create() { return null; }
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
    interface CustomerInterface {
        public function getId(); public function setId($id);
        public function getEmail(); public function setEmail($v);
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
        public function getEmail() { return $this->data['email'] ?? null; }
        public function setEmail($v) { return $this->s('email', $v); }
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

namespace Magento\Catalog\Api\Data {
    interface ProductInterface {
        public function getSku(); public function getName(); public function getTypeId();
        public function getStoreId(); public function getId();
    }
    interface ProductCustomOptionValuesInterface {
        public function getOptionTypeId(); public function getTitle(); public function getSortOrder();
        public function getPrice(); public function getPriceType(); public function getSku();
        public function setTitle($v); public function setSortOrder($v); public function setPrice($v);
        public function setPriceType($v); public function setSku($v);
    }
    interface ProductCustomOptionInterface {
        public function getProductSku(); public function getOptionId(); public function getTitle();
        public function getType(); public function getSortOrder(); public function getIsRequire();
        public function getPrice(); public function getPriceType(); public function getSku();
        public function getValues();
        public function setProductSku($v); public function setOptionId($v); public function setTitle($v);
        public function setType($v); public function setSortOrder($v); public function setIsRequire($v);
        public function setPrice($v); public function setPriceType($v); public function setSku($v);
        public function setValues(?array $v = null);
    }
}

namespace Magento\Catalog\Api {
    interface ProductRepositoryInterface {
        public function get($sku, $editMode = false, $storeId = null, $forceReload = false);
        public function getById($id, $editMode = false, $storeId = null, $forceReload = false);
        public function save(\Magento\Catalog\Api\Data\ProductInterface $product, $saveOptions = false);
        public function delete(\Magento\Catalog\Api\Data\ProductInterface $product);
        public function deleteById($sku);
        public function getList($searchCriteria);
    }
    interface ProductCustomOptionRepositoryInterface {
        public function getList($sku); public function get($sku, $optionId);
        public function save(\Magento\Catalog\Api\Data\ProductCustomOptionInterface $option);
        public function delete(\Magento\Catalog\Api\Data\ProductCustomOptionInterface $option);
        public function deleteByIdentifier($sku, $optionId);
    }
}

namespace Magento\Bundle\Api\Data {
    interface LinkInterface {
        public function getId(); public function getSku(); public function getOptionId();
        public function getQty(); public function getPosition(); public function getIsDefault();
        public function getPrice(); public function getPriceType(); public function getCanChangeQuantity();
        public function setId($v); public function setSku($v); public function setOptionId($v);
        public function setQty($v); public function setPosition($v); public function setIsDefault($v);
        public function setPrice($v); public function setPriceType($v); public function setCanChangeQuantity($v);
    }
    interface OptionInterface {
        public function getOptionId(); public function getTitle(); public function getRequired();
        public function getType(); public function getPosition(); public function getSku();
        public function getProductLinks();
        public function setOptionId($v); public function setTitle($v); public function setRequired($v);
        public function setType($v); public function setPosition($v); public function setSku($v);
        public function setProductLinks(?array $v = null);
    }
}

namespace Magento\Bundle\Api {
    interface ProductOptionRepositoryInterface {
        public function getList($sku); public function get($sku, $optionId);
        public function deleteById($sku, $optionId);
    }
    interface ProductOptionManagementInterface {
        public function save(\Magento\Bundle\Api\Data\OptionInterface $option);
        public function getList($sku);
    }
    interface ProductLinkManagementInterface {
        public function getChildren($productSku, $optionId = null);
        public function addChild(\Magento\Catalog\Api\Data\ProductInterface $product, $optionId, \Magento\Bundle\Api\Data\LinkInterface $linkedProduct);
        public function saveChild($sku, \Magento\Bundle\Api\Data\LinkInterface $linkedProduct);
        public function removeChild($sku, $optionId, $childSku);
    }
}

namespace Magento\Downloadable\Api\Data\File {
    interface ContentInterface {
        public function getFileData(); public function setFileData($v);
        public function getName(); public function setName($v);
    }
}

namespace Magento\Downloadable\Api\Data {
    interface LinkInterface {
        public function getId(); public function getTitle(); public function getSortOrder();
        public function getIsShareable(); public function getPrice(); public function getNumberOfDownloads();
        public function getLinkType(); public function getLinkFile(); public function getLinkUrl();
        public function getSampleType(); public function getSampleFile(); public function getSampleUrl();
        public function setId($v); public function setTitle($v); public function setSortOrder($v);
        public function setIsShareable($v); public function setPrice($v); public function setNumberOfDownloads($v);
        public function setLinkType($v); public function setLinkUrl($v); public function setLinkFileContent($v = null);
        public function setSampleType($v); public function setSampleUrl($v); public function setSampleFileContent($v = null);
    }
    interface SampleInterface {
        public function getId(); public function getTitle(); public function getSortOrder();
        public function getSampleType(); public function getSampleFile(); public function getSampleUrl();
        public function setId($v); public function setTitle($v); public function setSortOrder($v);
        public function setSampleType($v); public function setSampleUrl($v); public function setSampleFileContent($v = null);
    }
}

namespace Magento\Downloadable\Api {
    interface LinkRepositoryInterface {
        public function getList($sku);
        public function save($sku, \Magento\Downloadable\Api\Data\LinkInterface $link, $isGlobalScopeLink = false);
        public function delete($id);
    }
    interface SampleRepositoryInterface {
        public function getList($sku);
        public function save($sku, \Magento\Downloadable\Api\Data\SampleInterface $sample, $isGlobalScopeSample = false);
        public function delete($id);
    }
}

namespace Magento\Framework\App\Filesystem {
    class DirectoryList {
        public const LOG = 'log';
        public const MEDIA = 'media';
        public const VAR_DIR = 'var';
    }
}

namespace Magento\Framework\Filesystem\Directory {
    interface ReadInterface {
        public function isExist($path = null); public function isFile($path); public function stat($path);
        public function openFile($path, $flag = 'r'); public function readFile($path);
        public function read($path = null);
    }
}

namespace Magento\Framework\Filesystem\File {
    interface ReadInterface {
        public function read($length); public function readLine($length, $ending = null);
        public function seek($offset, $whence = SEEK_SET); public function eof(); public function close();
    }
}

namespace Magento\Framework {
    class DataObject {
        public function __construct(protected array $data = []) {}
        public function getCacheType() { return $this->data['cache_type'] ?? null; }
        public function getDescription() { return $this->data['description'] ?? null; }
        public function getId() { return $this->data['id'] ?? null; }
    }
}

namespace Magento\Framework\App\Cache {
    interface StateInterface {
        public function isEnabled($cacheType); public function setEnabled($cacheType, $isEnabled);
        public function persist();
    }
    interface TypeListInterface {
        public function getTypes(); public function getInvalidated();
        public function invalidate($typeCode); public function cleanType($typeCode);
    }
}

namespace Magento\Framework {
    class Filesystem {
        public function getDirectoryRead($code, $driverCode = 'file') { return null; }
        public function getDirectoryWrite($code, $driverCode = 'file') { return null; }
    }
}

namespace Magento\Cms\Api {
    interface BlockRepositoryInterface {
        public function save(\Magento\Cms\Api\Data\BlockInterface $block);
        public function getById($blockId);
        public function getList($searchCriteria);
        public function delete(\Magento\Cms\Api\Data\BlockInterface $block);
        public function deleteById($blockId);
    }
}

namespace Magento\Cms\Api\Data {
    interface BlockInterface {
        public function getId(); public function setId($id);
        public function getIdentifier(); public function setIdentifier($v);
        public function getTitle(); public function setTitle($v);
        public function getContent(); public function setContent($v);
        public function getCreationTime(); public function getUpdateTime();
        public function isActive(); public function setIsActive($v);
    }
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
    // The backend model base. Its real constructor takes seven collaborators a
    // unit test has no use for, so the stub swallows them: what the suite
    // exercises is beforeSave() against the value, nothing the parent holds.
    class Value {
        protected $data = [];
        public function __construct(...$arguments) {}
        public function getValue() { return $this->data['value'] ?? null; }
        public function setValue($value) { $this->data['value'] = $value; return $this; }
        public function beforeSave() { return $this; }
    }
}

namespace Magento\Framework\Model {
    class Context {}
    abstract class AbstractModel {}
}

namespace Magento\Framework\Model\ResourceModel {
    abstract class AbstractResource {}
}

namespace Magento\Framework\Data {
    interface OptionSourceInterface {
        public function toOptionArray();
    }
}

namespace Magento\Framework\Data\Collection {
    class AbstractDb {}
}

namespace Magento\Framework {
    class Registry {}
}

namespace Magento\Framework\Serialize\Serializer {
    class Json {
        public function serialize($data) { return json_encode($data); }
        public function unserialize($string) { return json_decode($string, true); }
    }
}

namespace Magento\User\Model {
    class User {
        public function getId() {}
        public function getData($key = null, $index = null) {}
    }
}

namespace Magento\User\Model\ResourceModel\User {
    class Collection implements \IteratorAggregate {
        public function addFieldToFilter($field, $condition = null) { return $this; }
        public function setOrder($field, $direction = 'DESC') { return $this; }
        public function setPageSize($size) { return $this; }
        public function setCurPage($page) { return $this; }
        public function getSize() { return 0; }
        public function getIterator(): \Traversable { return new \ArrayIterator([]); }
    }
    class CollectionFactory {
        public function create(array $data = []) { return null; }
    }
}

namespace Magenx\AdminActivity\Model {
    class Activity {
        public function getData($key = null, $index = null) {}
        public function load($modelId, $field = null) { return $this; }
    }
    class ActivityDetail {
        public function getData($key = null, $index = null) {}
    }
    class ActivityFactory {
        public function create(array $data = []) { return null; }
    }
    class Config {
        public function isEnabled() { return true; }
    }
}

namespace Magenx\AdminActivity\Model\Activity {
    class ActionType {
        public const ADD = 'add';
        public const EDIT = 'edit';
        public const DELETE = 'delete';
        public const VIEW = 'view';
        public const PRINT_ACTION = 'print';
        public const MASS_UPDATE = 'mass_update';
        public const LOGIN = 'login';
        public const LOGIN_FAILED = 'login_failed';
        public const LOGOUT = 'logout';
        public const PAGE_VISIT = 'page_visit';
        public const STATUS_SUCCESS = 'success';
        public const STATUS_FAILURE = 'failure';
    }
}

namespace Magenx\AdminActivity\Model\ResourceModel\Activity {
    class Collection implements \IteratorAggregate {
        public function addFieldToFilter($field, $condition = null) { return $this; }
        public function setOrder($field, $direction = 'DESC') { return $this; }
        public function setPageSize($size) { return $this; }
        public function setCurPage($page) { return $this; }
        public function getSize() { return 0; }
        public function getIterator(): \Traversable { return new \ArrayIterator([]); }
    }
    class CollectionFactory {
        public function create(array $data = []) { return null; }
    }
}

namespace Magenx\AdminActivity\Model\ResourceModel\ActivityDetail {
    class Collection implements \IteratorAggregate {
        public function addActivityFilter($activityId) { return $this; }
        public function getIterator(): \Traversable { return new \ArrayIterator([]); }
    }
    class CollectionFactory {
        public function create(array $data = []) { return null; }
    }
}

namespace Magenx\Platform\Model\Collector {
    interface CollectorInterface {
        public function getLabel();
        public function collect();
    }
}

namespace Magenx\Platform\Model\Metric {
    class Status {
        public const INFO = 'info';
        public const OK = 'ok';
        public const WARN = 'warn';
        public const UNAVAILABLE = 'unavailable';
        public const ERROR = 'error';
    }
}

namespace Magenx\Platform\Model {
    class CollectorPool {
        public function get($code) { return null; }
        public function getAll() { return []; }
    }
    class CollectorRunner {
        public function run($code, $collector) { return []; }
        public function unavailable($summary) { return []; }
    }
    class Config {
        public function isEnabled() { return true; }
        public function getEnabledCollectors() { return []; }
        public function getCacheTtl() { return 0; }
    }
}

namespace Magenx\Helpdesk\Model {
    class Priority { public function getId() {} public function getData($k = null, $i = null) {}
        public function setData($k, $v = null) { return $this; } }
    class Department extends Priority {}
    class Field extends Priority {}
    class SpamPattern extends Priority {}
    class PriorityFactory { public function create(array $data = []) { return null; } }
    class DepartmentFactory extends PriorityFactory {}
    class FieldFactory extends PriorityFactory {}
    class SpamPatternFactory extends PriorityFactory {}
}

namespace Magenx\Helpdesk\Model\ResourceModel {
    class Priority {
        public function load($object, $value, $field = null) { return $this; }
        public function save($object) { return $this; }
        public function delete($object) { return $this; }
    }
    class Department extends Priority {}
    class Field extends Priority {}
    class SpamPattern extends Priority {}
}

namespace Magenx\Rma\Api\Data {
    interface RMAInterface {
        public function getEntityId(); public function setOrderId($v); public function setStoreId($v);
        public function setCustomerId($v); public function setCustomerEmail($v);
        public function setCustomerName($v); public function setStatusId($v);
        public function setReasonId($v); public function setResolutionTypeId($v);
    }
    interface ItemInterface {
        public function setRmaId($v); public function setOrderItemId($v);
        public function setQtyRequested($v); public function setConditionId($v);
    }
    class RMAInterfaceFactory { public function create(array $data = []) { return null; } }
    class ItemInterfaceFactory { public function create(array $data = []) { return null; } }
}

namespace Magenx\Rma\Api {
    interface RMARepositoryInterface {
        public function get(int $entityId); public function save($rma);
    }
    interface ItemRepositoryInterface { public function save($item); }
}

namespace Magenx\Blog\Model {
    class Post {
        private $d = [];
        public function getId() { return $this->d['post_id'] ?? null; }
        public function getData($k = null, $i = null) { return $k === null ? $this->d : ($this->d[$k] ?? null); }
        public function setData($k, $v = null) { $this->d[$k] = $v; return $this; }
        public function hasData($k = null) { return array_key_exists($k, $this->d); }
        public function getUrlKey() { return $this->d['url_key'] ?? ''; }
    }
    class Category extends Post {}
    class Tag extends Post {}
    class PostFactory { public function create(array $data = []) { return null; } }
    class CategoryFactory extends PostFactory {}
    class TagFactory extends PostFactory {}
    class UrlKey { public function normalize(string $urlKey, string $fallback = '') { return ''; } }
    class PostRepository {
        public function getById(int $id) {} public function getByUrlKey(string $k) {}
        public function save($post) {} public function delete($post) {}
        public function getCategoryIds(int $id) { return []; } public function getTagIds(int $id) { return []; }
        public function getStoreIds(int $id) { return []; }
        public function getProductPositions(int $id) { return []; }
    }
    class CategoryRepository {
        public function getById(int $id) {} public function save($e) {} public function delete($e) {}
    }
    class TagRepository extends CategoryRepository {}
}

namespace Magenx\Blog\Model\ResourceModel\Post {
    class Collection implements \IteratorAggregate {
        public function addFieldToFilter($field, $condition = null) { return $this; }
        public function addCategoryFilter($id) { return $this; }
        public function addTagFilter($id) { return $this; }
        public function addStoreFilter($id) { return $this; }
        public function setOrder($field, $direction = 'DESC') { return $this; }
        public function setPageSize($size) { return $this; }
        public function setCurPage($page) { return $this; }
        public function getSize() { return 0; }
        public function getIterator(): \Traversable { return new \ArrayIterator([]); }
    }
    class CollectionFactory { public function create(array $data = []) { return null; } }
}

namespace Magenx\Blog\Model\ResourceModel\Category {
    class Collection extends \Magenx\Blog\Model\ResourceModel\Post\Collection {}
    class CollectionFactory { public function create(array $data = []) { return null; } }
}

namespace Magenx\Blog\Model\ResourceModel\Tag {
    class Collection extends \Magenx\Blog\Model\ResourceModel\Post\Collection {}
    class CollectionFactory { public function create(array $data = []) { return null; } }
}

namespace Magenx\Gdpr\Model {
    class DsrRequest {
        public const TYPE_EXPORT_DATA = 'export_data';
        public const TYPE_ANONYMIZE_DATA = 'anonymize_data';
        public const TYPE_ERASE_DATA = 'erase_data';
        public const STATUS_PENDING = 'pending';
        public const STATUS_APPROVED = 'approved';
        public const STATUS_DENIED = 'denied';
        public const STATUS_COMPLETED = 'completed';
        private $d = [];
        public function getId() { return $this->d['request_id'] ?? null; }
        public function getData($k = null, $i = null) { return $k === null ? $this->d : ($this->d[$k] ?? null); }
        public function setData($k, $v = null) { $this->d[$k] = $v; return $this; }
    }
    class Cookie extends DsrRequest {}
    class CookieGroup extends DsrRequest {}
    class DsrRequestFactory { public function create(array $data = []) { return null; } }
    class CookieFactory extends DsrRequestFactory {}
    class CookieGroupFactory extends DsrRequestFactory {}
    class Anonymizer {
        public function hasOpenOrders(int $customerId) { return false; }
        public function anonymizeCustomer(int $customerId) {}
    }
}

namespace Magenx\Gdpr\Model\ResourceModel {
    class DsrRequest {
        public function load($object, $value, $field = null) { return $this; }
        public function save($object) { return $this; }
        public function delete($object) { return $this; }
    }
    class Cookie extends DsrRequest {}
    class CookieGroup extends DsrRequest {}
}

namespace Magenx\Gdpr\Model\ResourceModel\DsrRequest {
    class Collection implements \IteratorAggregate {
        public function addFieldToFilter($field, $condition = null) { return $this; }
        public function setOrder($field, $direction = 'DESC') { return $this; }
        public function setPageSize($size) { return $this; }
        public function setCurPage($page) { return $this; }
        public function getSize() { return 0; }
        public function getIterator(): \Traversable { return new \ArrayIterator([]); }
    }
    class CollectionFactory { public function create(array $data = []) { return null; } }
}

namespace Magenx\Gdpr\Model\ResourceModel\ConsentLog {
    class Collection extends \Magenx\Gdpr\Model\ResourceModel\DsrRequest\Collection {}
    class CollectionFactory { public function create(array $data = []) { return null; } }
}

namespace Magenx\Gdpr\Model\ResourceModel\Cookie {
    class Collection extends \Magenx\Gdpr\Model\ResourceModel\DsrRequest\Collection {}
    class CollectionFactory { public function create(array $data = []) { return null; } }
}

namespace Magenx\Gdpr\Model\ResourceModel\CookieGroup {
    class Collection extends \Magenx\Gdpr\Model\ResourceModel\DsrRequest\Collection {}
    class CollectionFactory { public function create(array $data = []) { return null; } }
}

namespace Magento\Customer\Api\Data {
    interface GroupInterface {
        public function getId(); public function setId($id);
        public function getCode(); public function setCode($code);
        public function getTaxClassId(); public function setTaxClassId($id);
        public function getTaxClassName(); public function setTaxClassName($name);
    }
}

namespace Magento\Customer\Api {
    interface GroupRepositoryInterface {
        public function save(\Magento\Customer\Api\Data\GroupInterface $group);
        public function getById($id);
        public function getList(\Magento\Framework\Api\SearchCriteriaInterface $criteria);
        public function delete(\Magento\Customer\Api\Data\GroupInterface $group);
        public function deleteById($id);
    }
    interface CustomerRepositoryInterface {
        public function save($customer, $passwordHash = null);
        public function get($email, $websiteId = null);
        public function getById($id);
        public function getList(\Magento\Framework\Api\SearchCriteriaInterface $criteria);
        public function delete($customer);
        public function deleteById($id);
    }
}

namespace Magento\SalesRule\Api\Data {
    interface ConditionInterface {
        public function getConditionType(); public function setConditionType($type);
        public function getConditions(); public function setConditions(?array $conditions = null);
        public function getAggregatorType(); public function setAggregatorType($type);
        public function getOperator(); public function setOperator($operator);
        public function getAttributeName(); public function setAttributeName($name);
        public function getValue(); public function setValue($value);
    }
    interface RuleInterface {
        public function getRuleId(); public function setRuleId($id);
        public function getName(); public function setName($name);
        public function getDescription(); public function setDescription($description);
        public function getIsActive(); public function setIsActive($isActive);
        public function getCondition(); public function setCondition(?ConditionInterface $condition = null);
        public function getActionCondition(); public function setActionCondition(?ConditionInterface $condition = null);
        public function getSimpleAction(); public function setSimpleAction($action);
        public function getDiscountAmount(); public function setDiscountAmount($amount);
        public function getWebsiteIds(); public function setWebsiteIds($ids);
        public function getCustomerGroupIds(); public function setCustomerGroupIds($ids);
        public function getFromDate(); public function setFromDate($date);
        public function getToDate(); public function setToDate($date);
        public function getSortOrder(); public function setSortOrder($order);
        public function getUsesPerCustomer(); public function setUsesPerCustomer($uses);
        public function getStopRulesProcessing(); public function setStopRulesProcessing($stop);
        public function getCouponType(); public function setCouponType($type);
    }
}

namespace Magento\SalesRule\Api {
    interface RuleRepositoryInterface {
        public function save(\Magento\SalesRule\Api\Data\RuleInterface $rule);
        public function getById($id);
        public function getList(\Magento\Framework\Api\SearchCriteriaInterface $criteria);
        public function deleteById($id);
        public function delete(\Magento\SalesRule\Api\Data\RuleInterface $rule);
    }
}

namespace Magento\CatalogRule\Api\Data {
    interface ConditionInterface {
        public function getType(); public function setType($type);
        public function getConditions(); public function setConditions(?array $conditions = null);
    }
    interface RuleInterface {
        public function getRuleId(); public function setRuleId($id);
        public function getName(); public function setName($name);
        public function getDescription(); public function setDescription($description);
        public function getIsActive(); public function setIsActive($isActive);
        public function getRuleCondition(); public function setRuleCondition(?ConditionInterface $condition = null);
        public function getSimpleAction(); public function setSimpleAction($action);
        public function getDiscountAmount(); public function setDiscountAmount($amount);
        public function getWebsiteIds(); public function setWebsiteIds($ids);
        public function getCustomerGroupIds(); public function setCustomerGroupIds($ids);
        public function getStartDate(); public function setStartDate($date);
        public function getEndDate(); public function setEndDate($date);
        public function getSortOrder(); public function setSortOrder($order);
        public function getStopRulesProcessing(); public function setStopRulesProcessing($stop);
    }
}

namespace Magento\CatalogRule\Api {
    interface CatalogRuleRepositoryInterface {
        public function save(\Magento\CatalogRule\Api\Data\RuleInterface $rule);
        public function get($ruleId);
        public function delete(\Magento\CatalogRule\Api\Data\RuleInterface $rule);
        public function deleteById($ruleId);
    }
}

namespace Magento\MediaGalleryApi\Api\Data {
    interface AssetInterface {
        public function getId(); public function getPath(); public function getTitle();
        public function getContentType(); public function getWidth(); public function getHeight();
        public function getSize(); public function getCreatedAt(); public function getUpdatedAt();
    }
}

namespace Magento\MediaGalleryApi\Api {
    interface GetAssetsByPathsInterface {
        public function execute(array $paths): array;
    }
    interface DeleteAssetsByPathsInterface {
        public function execute(array $paths): void;
    }
    interface SearchAssetsInterface {
        public function execute(\Magento\Framework\Api\SearchCriteriaInterface $criteria): array;
    }
}

namespace Magento\MediaContentApi\Api\Data {
    interface ContentIdentityInterface {
        public function getEntityType(); public function getField(); public function getEntityId();
    }
}

namespace Magento\MediaContentApi\Api {
    interface GetContentByAssetIdsInterface {
        public function execute(array $assetIds): array;
    }
}

namespace Magento\Framework\Indexer {
    interface IndexerInterface {
        public function getId(); public function getTitle(); public function getStatus();
        public function isScheduled(); public function isValid(); public function isInvalid();
        public function invalidate();
    }
    class IndexerRegistry {
        public function get($indexerId) { return null; }
    }
}

namespace Magenx\ProductFeed\Model\Template\Exception {
    class TemplateSyntaxException extends \Exception {}
}

namespace Magenx\ProductFeed\Model\Template {
    class TemplateEngine {
        public function compile(string $source): array { return []; }
    }
}

namespace Magenx\ProductFeed\Model\Config\Source {
    class Delimiter {
        public const COMMA = 'comma'; public const SEMICOLON = 'semicolon';
        public const TAB = 'tab'; public const PIPE = 'pipe';
        public const COLON = 'colon'; public const SPACE = 'space';
    }
    class Enclosure {
        public const DOUBLE = 'double'; public const SINGLE = 'single'; public const NONE = 'none';
    }
}

namespace Magenx\ProductFeed\Model\Export {
    final class RunResult {
        public function __construct(
            public readonly bool $completed,
            public readonly bool $failed,
            public readonly int $productCount,
            public readonly int $durationMs,
            public readonly string $message = '',
            public readonly ?string $publishedPath = null,
            public readonly bool $skipped = false
        ) {}
        public static function progressed(int $count, int $durationMs): self {
            return new self(false, false, $count, $durationMs, 'Partially generated; will continue on the next run.');
        }
        public static function finished(int $count, int $durationMs, string $publishedPath): self {
            return new self(true, false, $count, $durationMs, 'Feed generated.', $publishedPath);
        }
        public static function error(string $message, int $durationMs, int $count = 0): self {
            return new self(false, true, $count, $durationMs, $message);
        }
        public static function skipped(string $message): self {
            return new self(false, false, 0, 0, $message, null, true);
        }
    }
    class FeedFilesystem {
        public function getRelativePath($feed, string $filename): string { return ''; }
        public function getPublicUrl($feed, string $filename): string { return ''; }
    }
}

namespace Magenx\ProductFeed\Model {
    class Feed {
        public const STATUS_NOT_GENERATED = 'not_generated';
        public const STATUS_PROCESSING = 'processing';
        public const STATUS_READY = 'ready';
        public const STATUS_WARNING = 'warning';
        public const STATUS_ERROR = 'error';
        public const STATUS_DISABLED = 'disabled';
        public const FORMAT_XML = 'xml';
        public const FORMAT_CSV = 'csv';
        public const FORMAT_TSV = 'tsv';
        public const FORMAT_JSONL = 'jsonl';

        private $d = [];
        public function getFeedId(): ?int {
            return isset($this->d['feed_id']) ? (int) $this->d['feed_id'] : null;
        }
        public function getCode(): string { return (string) ($this->d['code'] ?? ''); }
        public function getStoreId(): int { return (int) ($this->d['store_id'] ?? 0); }
        public function getFormat(): string { return (string) ($this->d['format'] ?? 'xml'); }
        public function isActive(): bool { return (bool) ($this->d['is_active'] ?? false); }
        public function producesRecords(): bool {
            return in_array($this->getFormat(), ['csv', 'tsv', 'jsonl'], true);
        }
        public function getData($k = null, $i = null) {
            return $k === null ? $this->d : ($this->d[$k] ?? null);
        }
        public function setData($k, $v = null) { $this->d[$k] = $v; return $this; }
        public function hasData($k = null) { return array_key_exists($k, $this->d); }
        public function getFieldMap(): array {
            $raw = (string) ($this->d['field_map'] ?? '');
            if ($raw === '') { return []; }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        public function getValidationRules(): array {
            $raw = (string) ($this->d['validation_rules'] ?? '');
            if ($raw === '') { return []; }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        public function getScheduleDays(): array {
            $raw = trim((string) ($this->d['schedule_days'] ?? ''));
            return $raw === '' ? [] : array_map('intval', explode(',', $raw));
        }
        public function getScheduleTimes(): array {
            $raw = trim((string) ($this->d['schedule_times'] ?? ''));
            return $raw === '' ? [] : explode(',', $raw);
        }
    }
    class FeedFactory { public function create(array $data = []) { return null; } }
    class FeedHistory {
        public const TYPE_GENERATE = 'generate';
        public const TYPE_DELIVER = 'deliver';
        public const TYPE_VALIDATE = 'validate';
    }
    class FeedManager {
        public function process(Feed $feed) {}
        public function deliver(Feed $feed) { return []; }
    }
    class Config {
        public function isEnabled(?int $storeId = null): bool { return false; }
    }
    class Delivery {
        private $d = [];
        public function getId() { return $this->d['delivery_id'] ?? null; }
        public function getType(): string { return (string) ($this->d['type'] ?? ''); }
        public function isActive(): bool { return (bool) ($this->d['is_active'] ?? false); }
        public function getConfigData(): array { return $this->d['config'] ?? []; }
        public function getData($k = null, $i = null) {
            return $k === null ? $this->d : ($this->d[$k] ?? null);
        }
        public function setData($k, $v = null) { $this->d[$k] = $v; return $this; }
    }
    class History {
        private $d = [];
        public function getId() { return $this->d['history_id'] ?? null; }
        public function getDetails(): array { return $this->d['details'] ?? []; }
        public function getData($k = null, $i = null) {
            return $k === null ? $this->d : ($this->d[$k] ?? null);
        }
        public function setData($k, $v = null) { $this->d[$k] = $v; return $this; }
    }
}

namespace Magenx\ProductFeed\Model\ResourceModel {
    class Feed {
        public function load($object, $value, $field = null) { return $this; }
        public function save($object) { return $this; }
        public function delete($object) { return $this; }
        public function getIdByCode(string $code): ?int { return null; }
    }
    class Delivery extends Feed {}
    class History extends Feed {}
}

namespace Magenx\ProductFeed\Model\ResourceModel\Feed {
    class Collection implements \IteratorAggregate {
        public function addFieldToFilter($field, $condition = null) { return $this; }
        public function addActiveFilter() { return $this; }
        public function addStoreFilter($id) { return $this; }
        public function setOrder($field, $direction = 'DESC') { return $this; }
        public function setPageSize($size) { return $this; }
        public function setCurPage($page) { return $this; }
        public function getSize() { return 0; }
        public function getIterator(): \Traversable { return new \ArrayIterator([]); }
    }
    class CollectionFactory { public function create(array $data = []) { return null; } }
}

namespace Magenx\ProductFeed\Model\ResourceModel\Delivery {
    class Collection extends \Magenx\ProductFeed\Model\ResourceModel\Feed\Collection {
        public function addFeedFilter(int $feedId) { return $this; }
    }
    class CollectionFactory { public function create(array $data = []) { return null; } }
}

namespace Magenx\ProductFeed\Model\ResourceModel\History {
    class Collection extends \Magenx\ProductFeed\Model\ResourceModel\Feed\Collection {
        public function addFeedFilter(int $feedId) { return $this; }
    }
    class CollectionFactory { public function create(array $data = []) { return null; } }
}

namespace Magento\Quote\Api\Data {
    interface CurrencyInterface {
        public function getQuoteCurrencyCode(); public function getBaseCurrencyCode();
    }
    interface CartItemInterface {
        public function getItemId(); public function setItemId($id);
        public function getSku(); public function setSku($sku);
        public function getQty(); public function setQty($qty);
        public function getName(); public function setName($name);
        public function getPrice(); public function setPrice($price);
        public function getProductType(); public function setProductType($type);
        public function getQuoteId(); public function setQuoteId($id);
    }
    interface CartInterface {
        public function getId(); public function getStoreId(); public function setStoreId($id);
        public function getIsActive(); public function getIsVirtual();
        public function getItems(); public function getItemsCount(); public function getItemsQty();
        public function getCustomer(); public function getCustomerIsGuest();
        public function getCustomerNote(); public function getCurrency();
        public function getReservedOrderId(); public function getConvertedAt();
        public function getCreatedAt(); public function getUpdatedAt();
    }
    interface AddressInterface {
        public function getId();
        public function getFirstname(); public function setFirstname($v);
        public function getLastname(); public function setLastname($v);
        public function getMiddlename(); public function setMiddlename($v);
        public function getPrefix(); public function setPrefix($v);
        public function getSuffix(); public function setSuffix($v);
        public function getCompany(); public function setCompany($v);
        public function getStreet(); public function setStreet(array $street);
        public function getCity(); public function setCity($v);
        public function getRegion(); public function setRegion($v);
        public function getRegionId(); public function setRegionId($v);
        public function getRegionCode(); public function setRegionCode($v);
        public function getPostcode(); public function setPostcode($v);
        public function getCountryId(); public function setCountryId($v);
        public function getTelephone(); public function setTelephone($v);
        public function getFax(); public function setFax($v);
        public function getVatId(); public function setVatId($v);
        public function getEmail(); public function setEmail($v);
    }
    interface PaymentInterface {
        public function getMethod(); public function setMethod($method);
        public function getPoNumber(); public function setPoNumber($poNumber);
        public function getAdditionalData(); public function setAdditionalData($data);
    }
    interface PaymentMethodInterface {
        public function getCode(); public function getTitle();
    }
    interface ShippingMethodInterface {
        public function getCarrierCode(); public function getMethodCode();
        public function getCarrierTitle(); public function getMethodTitle();
        public function getAmount(); public function getBaseAmount();
        public function getPriceExclTax(); public function getPriceInclTax();
        public function getAvailable(); public function getErrorMessage();
    }
    interface TotalsInterface {
        public function getGrandTotal(); public function getBaseGrandTotal();
        public function getSubtotal(); public function getSubtotalInclTax();
        public function getBaseSubtotal(); public function getDiscountAmount();
        public function getShippingAmount(); public function getShippingInclTax();
        public function getTaxAmount(); public function getItemsQty();
        public function getCouponCode();
        public function getQuoteCurrencyCode(); public function getBaseCurrencyCode();
    }
    class Address implements AddressInterface {
        private array $d = [];
        public function getId() { return $this->d['Id'] ?? null; }
        public function getFirstname() { return $this->d['Firstname'] ?? null; }
        public function getLastname() { return $this->d['Lastname'] ?? null; }
        public function getMiddlename() { return $this->d['Middlename'] ?? null; }
        public function getPrefix() { return $this->d['Prefix'] ?? null; }
        public function getSuffix() { return $this->d['Suffix'] ?? null; }
        public function getCompany() { return $this->d['Company'] ?? null; }
        public function getStreet() { return $this->d['Street'] ?? null; }
        public function getCity() { return $this->d['City'] ?? null; }
        public function getRegion() { return $this->d['Region'] ?? null; }
        public function getRegionId() { return $this->d['RegionId'] ?? null; }
        public function getRegionCode() { return $this->d['RegionCode'] ?? null; }
        public function getPostcode() { return $this->d['Postcode'] ?? null; }
        public function getCountryId() { return $this->d['CountryId'] ?? null; }
        public function getTelephone() { return $this->d['Telephone'] ?? null; }
        public function getFax() { return $this->d['Fax'] ?? null; }
        public function getVatId() { return $this->d['VatId'] ?? null; }
        public function getEmail() { return $this->d['Email'] ?? null; }
        public function setFirstname($v) { $this->d['Firstname'] = $v; return $this; }
        public function setLastname($v) { $this->d['Lastname'] = $v; return $this; }
        public function setMiddlename($v) { $this->d['Middlename'] = $v; return $this; }
        public function setPrefix($v) { $this->d['Prefix'] = $v; return $this; }
        public function setSuffix($v) { $this->d['Suffix'] = $v; return $this; }
        public function setCompany($v) { $this->d['Company'] = $v; return $this; }
        public function setStreet(array $v) { $this->d['Street'] = $v; return $this; }
        public function setCity($v) { $this->d['City'] = $v; return $this; }
        public function setRegion($v) { $this->d['Region'] = $v; return $this; }
        public function setRegionId($v) { $this->d['RegionId'] = $v; return $this; }
        public function setRegionCode($v) { $this->d['RegionCode'] = $v; return $this; }
        public function setPostcode($v) { $this->d['Postcode'] = $v; return $this; }
        public function setCountryId($v) { $this->d['CountryId'] = $v; return $this; }
        public function setTelephone($v) { $this->d['Telephone'] = $v; return $this; }
        public function setFax($v) { $this->d['Fax'] = $v; return $this; }
        public function setVatId($v) { $this->d['VatId'] = $v; return $this; }
        public function setEmail($v) { $this->d['Email'] = $v; return $this; }
    }
    class AddressInterfaceFactory { public function create(array $data = []) { return null; } }
    class CartItemInterfaceFactory { public function create(array $data = []) { return null; } }
    class PaymentInterfaceFactory { public function create(array $data = []) { return null; } }
}

namespace Magento\Quote\Api {
    interface CartManagementInterface {
        public function createEmptyCart();
        public function createEmptyCartForCustomer($customerId);
        public function getCartForCustomer($customerId);
        public function assignCustomer($cartId, $customerId, $storeId);
        public function placeOrder($cartId, ?\Magento\Quote\Api\Data\PaymentInterface $paymentMethod = null);
    }
    interface CartRepositoryInterface {
        public function get($cartId);
        public function getList(\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria);
        public function getForCustomer($customerId, array $sharedStoreIds = []);
        public function getActive($cartId, array $sharedStoreIds = []);
        public function save(\Magento\Quote\Api\Data\CartInterface $quote);
        public function delete(\Magento\Quote\Api\Data\CartInterface $quote);
    }
    interface CartItemRepositoryInterface {
        public function getList($cartId);
        public function save(\Magento\Quote\Api\Data\CartItemInterface $cartItem);
        public function deleteById($cartId, $itemId);
    }
    interface CartTotalRepositoryInterface {
        public function get($cartId);
    }
    interface PaymentMethodManagementInterface {
        public function set($cartId, \Magento\Quote\Api\Data\PaymentInterface $method);
        public function get($cartId);
        public function getList($cartId);
    }
    interface BillingAddressManagementInterface {
        public function assign($cartId, \Magento\Quote\Api\Data\AddressInterface $address, $useForShipping = false);
        public function get($cartId);
    }
    interface ShipmentEstimationInterface {
        public function estimateByExtendedAddress($cartId, \Magento\Quote\Api\Data\AddressInterface $address);
    }
}

namespace Magento\Checkout\Api\Data {
    interface ShippingInformationInterface {
        public function getShippingAddress();
        public function setShippingAddress(\Magento\Quote\Api\Data\AddressInterface $address);
        public function getBillingAddress();
        public function setBillingAddress(\Magento\Quote\Api\Data\AddressInterface $address);
        public function getShippingMethodCode(); public function setShippingMethodCode($code);
        public function getShippingCarrierCode(); public function setShippingCarrierCode($code);
    }
    interface PaymentDetailsInterface {
        public function getPaymentMethods(); public function getTotals();
    }
    class ShippingInformationInterfaceFactory { public function create(array $data = []) { return null; } }
}

namespace Magento\Checkout\Api {
    interface ShippingInformationManagementInterface {
        public function saveAddressInformation(
            $cartId,
            \Magento\Checkout\Api\Data\ShippingInformationInterface $addressInformation
        );
    }
}

namespace Magento\Store\Model {
    class Store {
        public const DEFAULT_STORE_ID = 0;
        public function getId() { return null; }
        public function getCode() { return null; }
        public function getRootCategoryId() { return null; }
    }
    interface StoreManagerInterface {
        public function getStore($storeId = null);
        public function getStores($withDefault = false, $codeKey = false);
    }
}

namespace Magenx\QuickSearchGraphQl\Model\Source {
    class Type {
        public const PRODUCT = 'product';
        public const CATEGORY = 'category';
        public const BRAND = 'brand';
    }
}

namespace Magenx\QuickSearchGraphQl\Model {
    class Promotion {
        private $d = [];
        public function getId() { return $this->d['promotion_id'] ?? null; }
        public function getData($k = null, $i = null) {
            return $k === null ? $this->d : ($this->d[$k] ?? null);
        }
        public function setData($k, $v = null) { $this->d[$k] = $v; return $this; }
    }
    class PromotionFactory { public function create(array $data = []) { return null; } }
    class PromotionRepository {
        public function getById(int $promotionId) { return null; }
        public function save($promotion) { return $promotion; }
        public function delete($promotion): void {}
    }
    class Config {
        public function isPromotionsEnabled(?int $storeId = null): bool { return true; }
        public function getPromotionsMaxItems(?int $storeId = null): int { return 10; }
    }
    class PromotionProvider {
        public function getActive(int $storeId, int $limit): array { return []; }
    }
    class TargetLoader {
        public function loadProducts(array $skus, int $storeId): array { return []; }
        public function loadCategories(array $categoryIds, int $storeId, int $rootCategoryId): array { return []; }
        public function loadBrands(array $optionIds, int $storeId): array { return []; }
    }
}

