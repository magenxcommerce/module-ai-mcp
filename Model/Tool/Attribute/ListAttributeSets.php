<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Attribute;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Api\ProductAttributeManagementInterface;
use Magento\Eav\Api\AttributeGroupRepositoryInterface;
use Magento\Eav\Api\AttributeSetRepositoryInterface;
use Magento\Eav\Api\Data\AttributeGroupInterface;
use Magento\Eav\Api\Data\AttributeSetInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * List the product attribute sets, and what one contains.
 *
 * Given an attribute_set_id it also returns the set's groups and attribute
 * codes, because assign_product_attribute_to_set needs a group id and there is
 * otherwise no way to discover one — which would leave the assign tool
 * unusable without the admin.
 */
class ListAttributeSets extends AbstractTool
{
    /**
     * @param AttributeSetRepositoryInterface $attributeSetRepository
     * @param AttributeGroupRepositoryInterface $attributeGroupRepository
     * @param ProductAttributeManagementInterface $attributeManagement
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param EavConfig $eavConfig
     */
    public function __construct(
        private readonly AttributeSetRepositoryInterface $attributeSetRepository,
        private readonly AttributeGroupRepositoryInterface $attributeGroupRepository,
        private readonly ProductAttributeManagementInterface $attributeManagement,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'list_attribute_sets';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the product attribute sets. Pass attribute_set_id to also get that set\'s '
            . 'groups, whose ids assign_product_attribute_to_set needs, and the attribute codes it '
            . 'already contains.';
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
                    'attribute_set_id' => [
                        'type' => 'integer',
                        'description' => 'Return this one set, with its groups and attribute codes.',
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
        return 'Magento_Catalog::sets';
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $attributeSetId = $this->optionalInt($arguments, 'attribute_set_id');
        if ($attributeSetId !== null) {
            return $this->oneSet($attributeSetId);
        }

        $pageSize = $this->pageSize($arguments);
        $currentPage = $this->currentPage($arguments);
        $this->searchCriteriaBuilder->setPageSize($pageSize);
        $this->searchCriteriaBuilder->setCurrentPage($currentPage);
        // Attribute sets exist for every EAV entity — customers and categories
        // among them — so without this the list is mostly sets no product tool
        // can use.
        $this->searchCriteriaBuilder->addFilter('entity_type_id', $this->productEntityTypeId(), 'eq');

        $result = $this->attributeSetRepository->getList($this->searchCriteriaBuilder->create());

        return [
            'total_count' => (int) $result->getTotalCount(),
            'page' => $currentPage,
            'page_size' => $pageSize,
            'items' => array_map(
                static fn (AttributeSetInterface $set): array => [
                    'attribute_set_id' => (int) $set->getAttributeSetId(),
                    'attribute_set_name' => $set->getAttributeSetName(),
                    'sort_order' => $set->getSortOrder() === null ? null : (int) $set->getSortOrder(),
                ],
                array_values($result->getItems())
            ),
        ];
    }

    /**
     * @param int $attributeSetId
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    private function oneSet(int $attributeSetId): array
    {
        try {
            $set = $this->attributeSetRepository->get($attributeSetId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(
                __('No attribute set exists with attribute_set_id %1.', $attributeSetId)
            );
        }

        $this->searchCriteriaBuilder->addFilter('attribute_set_id', $attributeSetId);
        $groups = $this->attributeGroupRepository->getList($this->searchCriteriaBuilder->create())->getItems();

        return [
            'attribute_set_id' => (int) $set->getAttributeSetId(),
            'attribute_set_name' => $set->getAttributeSetName(),
            'sort_order' => $set->getSortOrder() === null ? null : (int) $set->getSortOrder(),
            'groups' => array_map(
                static fn (AttributeGroupInterface $group): array => [
                    'attribute_group_id' => (int) $group->getAttributeGroupId(),
                    'attribute_group_name' => $group->getAttributeGroupName(),
                ],
                array_values($groups)
            ),
            'attribute_codes' => array_values(array_map(
                static fn (ProductAttributeInterface $attribute): string => (string) $attribute->getAttributeCode(),
                $this->attributeManagement->getAttributes($attributeSetId)
            )),
        ];
    }

    /**
     * The entity type id of catalog_product.
     *
     * Resolved from the entity type code rather than assumed: the numeric id is
     * assigned at install time and is not guaranteed to be the same on two
     * installations.
     *
     * @return int
     * @throws LocalizedException
     */
    private function productEntityTypeId(): int
    {
        return (int) $this->eavConfig
            ->getEntityType(ProductAttributeInterface::ENTITY_TYPE_CODE)
            ->getId();
    }
}
