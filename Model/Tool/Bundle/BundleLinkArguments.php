<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Bundle;

use Magento\Bundle\Api\Data\LinkInterface;
use Magento\Bundle\Api\Data\LinkInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Turns one selection argument into a bundle link.
 *
 * Shared by save_bundle_option, which takes a whole list of them, and
 * add_bundle_selection, which takes one — so the validation and the defaults
 * cannot drift between the two.
 */
class BundleLinkArguments
{
    /** 0 = a fixed amount, 1 = a percentage of the bundle price. */
    private const PRICE_TYPES = [0, 1];

    /**
     * @param LinkInterfaceFactory $linkFactory
     */
    public function __construct(
        private readonly LinkInterfaceFactory $linkFactory
    ) {
    }

    /**
     * Schema for one selection.
     *
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => [
                    'type' => 'string',
                    'description' => 'Sku of the product offered by this selection. It has to exist '
                        . 'already; a bundle never creates its own children.',
                ],
                'qty' => [
                    'type' => 'number',
                    'description' => 'How many of that product one unit of the bundle includes. '
                        . 'Defaults to 1.',
                ],
                'position' => ['type' => 'integer', 'description' => 'Sort position within the option.'],
                'is_default' => [
                    'type' => 'boolean',
                    'description' => 'Preselect this one on the product page.',
                ],
                'can_change_quantity' => [
                    'type' => 'boolean',
                    'description' => 'Let the customer change the quantity of this selection.',
                ],
                'price' => [
                    'type' => 'number',
                    'description' => 'Selection price. Only used by a bundle whose price_type is '
                        . 'fixed; a dynamic bundle takes the child product\'s own price and '
                        . 'ignores this.',
                ],
                'price_type' => [
                    'type' => 'integer',
                    'enum' => self::PRICE_TYPES,
                    'description' => '0 for a fixed amount, 1 for a percentage of the bundle price.',
                ],
            ],
            'required' => ['sku'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param int|null $optionId Set on the link when the option already exists.
     * @return LinkInterface
     * @throws LocalizedException
     */
    public function build(array $arguments, ?int $optionId = null): LinkInterface
    {
        $sku = $arguments['sku'] ?? null;
        if (!is_string($sku) || trim($sku) === '') {
            throw new LocalizedException(__('Every selection needs a "sku".'));
        }

        $link = $this->linkFactory->create();
        $link->setSku(trim($sku));
        $link->setQty($this->number($arguments, 'qty') ?? 1.0);
        $link->setPosition((int) ($this->number($arguments, 'position') ?? 0));

        if ($optionId !== null) {
            $link->setOptionId($optionId);
        }

        $link->setIsDefault($this->flag($arguments, 'is_default'));
        $link->setCanChangeQuantity($this->flag($arguments, 'can_change_quantity') ? 1 : 0);

        $price = $this->number($arguments, 'price');
        if ($price !== null) {
            $link->setPrice($price);
        }

        if (array_key_exists('price_type', $arguments) && $arguments['price_type'] !== null) {
            $priceType = $arguments['price_type'];
            if (!is_int($priceType) || !in_array($priceType, self::PRICE_TYPES, true)) {
                throw new LocalizedException(__(
                    'A selection\'s "price_type" must be 0 (fixed amount) or 1 (percentage).'
                ));
            }
            $link->setPriceType($priceType);
        }

        return $link;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string $key
     * @return float|null
     * @throws LocalizedException
     */
    private function number(array $arguments, string $key): ?float
    {
        if (!array_key_exists($key, $arguments) || $arguments[$key] === null) {
            return null;
        }

        $value = $arguments[$key];
        if (!is_int($value) && !is_float($value)) {
            throw new LocalizedException(
                __('A selection\'s "%1" must be a number.', $key)
            );
        }

        return (float) $value;
    }

    /**
     * Not a (bool) cast: JSON has real booleans, and a cast turns the string
     * "false" — which a model does produce — into true.
     *
     * @param array<string, mixed> $arguments
     * @param string $key
     * @return bool
     * @throws LocalizedException
     */
    private function flag(array $arguments, string $key): bool
    {
        if (!array_key_exists($key, $arguments) || $arguments[$key] === null) {
            return false;
        }

        if (!is_bool($arguments[$key])) {
            throw new LocalizedException(
                __('A selection\'s "%1" must be true or false.', $key)
            );
        }

        return $arguments[$key];
    }
}
