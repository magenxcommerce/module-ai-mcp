<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Catalog;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

/**
 * List product attributes and their selectable option values.
 *
 * Needed before any attribute write: setting a dropdown attribute requires the
 * option *id*, and guessing one is how an agent silently writes the wrong
 * value.
 */
class ListAttributes extends AbstractTool
{
    /**
     * @param ProductAttributeRepositoryInterface $attributeRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly ProductAttributeRepositoryInterface $attributeRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_product_attributes';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List product attributes with their code, type, scope and whether they are required. '
            . 'Pass attribute_code to get one attribute including its selectable option ids and labels, '
            . 'which you need before writing a dropdown or multiselect value.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'attribute_code' => [
                        'type' => 'string',
                        'description' => 'Return only this attribute, including its options.',
                    ],
                ],
                $this->pagingSchema()
            ),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magento_Catalog::attributes_attributes';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $code = $this->optionalString($arguments, 'attribute_code');
        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);

        if ($code !== null) {
            $this->searchCriteriaBuilder->addFilter('attribute_code', $code);
        }
        $this->searchCriteriaBuilder->setPageSize($pageSize);
        $this->searchCriteriaBuilder->setCurrentPage($currentPage);

        $result = $this->attributeRepository->getList($this->searchCriteriaBuilder->create());

        $items = [];
        foreach ($result->getItems() as $attribute) {
            $item = [
                'attribute_code' => $attribute->getAttributeCode(),
                'default_frontend_label' => $attribute->getDefaultFrontendLabel(),
                'frontend_input' => $attribute->getFrontendInput(),
                'backend_type' => $attribute->getBackendType(),
                'is_required' => (bool) $attribute->getIsRequired(),
                'scope' => $attribute->getScope(),
                'is_user_defined' => (bool) $attribute->getIsUserDefined(),
            ];

            // Options are only worth their size when one attribute was asked for.
            if ($code !== null) {
                $options = [];
                foreach ((array) $attribute->getOptions() as $option) {
                    $value = $option->getValue();
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $options[] = ['value' => $value, 'label' => $option->getLabel()];
                }
                $item['options'] = $options;
            }

            $items[] = $item;
        }

        return [
            'total_count' => (int) $result->getTotalCount(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => $items,
        ];
    }
}
