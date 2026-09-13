<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Price;

use Magento\Catalog\Api\Data\PriceUpdateResultInterface;
use Magento\Catalog\Api\Data\TierPriceInterface;
use Magento\Catalog\Api\Data\TierPriceInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Builds and validates the tier price rows the price tools exchange.
 *
 * Also owns the one thing about `TierPriceStorageInterface` that is easy to get
 * wrong: it does not throw when a row is rejected. It returns a list of
 * failures, and an empty list is the only sign of success. Treating that return
 * as "done" would report a price change that never happened, so the failures
 * are turned into a tool error here.
 */
class TierPriceArguments
{
    /** Magento's own name for "applies to every customer group". */
    public const ALL_GROUPS = 'ALL GROUPS';

    /**
     * @param TierPriceInterfaceFactory $tierPriceFactory
     */
    public function __construct(
        private readonly TierPriceInterfaceFactory $tierPriceFactory
    ) {
    }

    /**
     * Turn the `prices` argument into tier price objects.
     *
     * @param array<mixed> $prices
     * @param bool $requireValue Whether each row must carry a price; deletes do not.
     * @return TierPriceInterface[]
     * @throws LocalizedException
     */
    public function build(array $prices, bool $requireValue): array
    {
        if ($prices === []) {
            throw new LocalizedException(__('Pass at least one row in "prices".'));
        }

        $built = [];
        $seen = [];
        foreach ($prices as $index => $row) {
            $position = (int) $index + 1;
            if (!is_array($row)) {
                throw new LocalizedException(__('Row %1 must be an object.', $position));
            }

            $sku = $row['sku'] ?? null;
            if (!is_string($sku) || trim($sku) === '') {
                throw new LocalizedException(__('Row %1 needs a "sku".', $position));
            }
            $sku = trim($sku);

            $quantity = $this->number($row['quantity'] ?? null, 'quantity', $position);
            if ($quantity < 1) {
                throw new LocalizedException(
                    __('Row %1 has quantity %2; a tier starts at 1.', $position, $quantity)
                );
            }

            $customerGroup = $row['customer_group'] ?? self::ALL_GROUPS;
            if (!is_string($customerGroup) || trim($customerGroup) === '') {
                throw new LocalizedException(__(
                    'Row %1 has an unusable "customer_group". Pass a group code such as "General", '
                    . 'or "%2" to apply to everyone.',
                    $position,
                    self::ALL_GROUPS
                ));
            }
            $customerGroup = trim($customerGroup);

            $websiteId = $row['website_id'] ?? 0;
            if (!is_int($websiteId) && !(is_string($websiteId) && ctype_digit($websiteId))) {
                throw new LocalizedException(
                    __('Row %1 has an unusable "website_id"; pass 0 for all websites.', $position)
                );
            }
            $websiteId = (int) $websiteId;

            // A tier is identified by these three together, so two rows sharing
            // them are two different intentions for one price and Magento would
            // silently keep whichever it applied last.
            $key = strtolower($sku) . '|' . $quantity . '|' . strtolower($customerGroup) . '|' . $websiteId;
            if (isset($seen[$key])) {
                throw new LocalizedException(__(
                    'Rows %1 and %2 both set the tier for sku "%3" at quantity %4 for customer '
                    . 'group "%5". Give it once.',
                    $seen[$key],
                    $position,
                    $sku,
                    $quantity,
                    $customerGroup
                ));
            }
            $seen[$key] = $position;

            $tierPrice = $this->tierPriceFactory->create();
            $tierPrice->setSku($sku);
            $tierPrice->setQuantity($quantity);
            $tierPrice->setCustomerGroup($customerGroup);
            $tierPrice->setWebsiteId($websiteId);

            if ($requireValue) {
                $tierPrice->setPrice($this->number($row['price'] ?? null, 'price', $position));
                $tierPrice->setPriceType($this->priceType($row, $position));
            }

            $built[] = $tierPrice;
        }

        return $built;
    }

    /**
     * Schema fragment for one tier price row.
     *
     * @param bool $withValue Whether the row carries a price.
     * @param string $description What the list means for this tool.
     * @return array<string, mixed>
     */
    public function schemaProperty(bool $withValue, string $description): array
    {
        $properties = [
            'sku' => ['type' => 'string', 'description' => 'Product sku.'],
            'quantity' => [
                'type' => 'number',
                'minimum' => 1,
                'description' => 'Quantity from which this price applies.',
            ],
            'customer_group' => [
                'type' => 'string',
                'description' => 'Customer group code, e.g. "General" or "Wholesale", as '
                    . 'list_customer_groups reports under "code". Defaults to "'
                    . self::ALL_GROUPS . '", which applies to everyone.',
            ],
            'website_id' => [
                'type' => 'integer',
                'description' => 'Website the price applies to. Defaults to 0, meaning all '
                    . 'websites. Only accepted when the store is configured to scope prices per '
                    . 'website.',
            ],
        ];

        if ($withValue) {
            $properties['price'] = [
                'type' => 'number',
                'description' => 'The tier price: an amount when price_type is "fixed", or a '
                    . 'percentage off when it is "discount".',
            ];
            $properties['price_type'] = [
                'type' => 'string',
                'enum' => ['fixed', 'discount'],
                'description' => 'Whether "price" is an amount ("fixed") or a percentage discount '
                    . 'off the product price ("discount"). Defaults to "fixed".',
            ];
        }

        return [
            'type' => 'array',
            'description' => $description,
            'items' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => $withValue ? ['sku', 'quantity', 'price'] : ['sku', 'quantity'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * Turn what the storage returns into a tool error, or pass silently.
     *
     * @param PriceUpdateResultInterface[] $failures
     * @param string $operation
     * @return void
     * @throws LocalizedException
     */
    public function assertNoFailures(array $failures, string $operation): void
    {
        if ($failures === []) {
            return;
        }

        $messages = [];
        foreach ($failures as $failure) {
            $messages[] = $this->render((string) $failure->getMessage(), $failure->getParameters());
        }

        throw new LocalizedException(__(
            'Magento rejected the %1 and changed nothing for the rows it names: %2',
            $operation,
            implode(' | ', $messages)
        ));
    }

    /**
     * Fill a failure message's placeholders.
     *
     * These are named rather than numbered — "%SKU", "%qty" — and arrive in a
     * map beside the template. Longer names are substituted first so that a
     * placeholder which is a prefix of another, such as "%price" inside
     * "%priceType", cannot eat it.
     *
     * @param string $message
     * @param array<string, mixed> $parameters
     * @return string
     */
    private function render(string $message, array $parameters): string
    {
        $names = array_keys($parameters);
        usort($names, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($names as $name) {
            $value = $parameters[$name];
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $message = str_replace('%' . $name, (string) $value, $message);
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $row
     * @param int $position
     * @return string
     * @throws LocalizedException
     */
    private function priceType(array $row, int $position): string
    {
        $type = $row['price_type'] ?? TierPriceInterface::PRICE_TYPE_FIXED;
        if (!is_string($type) || !in_array($type, ['fixed', 'discount'], true)) {
            throw new LocalizedException(
                __('Row %1 has an unusable "price_type"; pass "fixed" or "discount".', $position)
            );
        }

        return $type;
    }

    /**
     * @param mixed $value
     * @param string $field
     * @param int $position
     * @return float
     * @throws LocalizedException
     */
    private function number(mixed $value, string $field, int $position): float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            throw new LocalizedException(__('Row %1 needs a numeric "%2".', $position, $field));
        }

        return (float) $value;
    }
}
