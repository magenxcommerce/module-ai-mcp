<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Cms;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;

/**
 * Finds the one CMS block a call means.
 *
 * A block identifier is not unique — Magento allows the same identifier on
 * several blocks assigned to different store views. Taking the first row would
 * act on an arbitrary one of them and report success naming only the
 * identifier, leaving the agent with no way to know what it touched. So an
 * ambiguous identifier is an error that names the candidates instead, and the
 * error says what would have happened to the block it could not choose.
 *
 * The page-side counterpart is {@see PageLocator}.
 */
class BlockLocator
{
    /**
     * @param BlockRepositoryInterface $blockRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @param string $identifier
     * @param int|null $blockId
     * @param string $verb What the caller is about to do — "change", "delete", "read".
     * @return BlockInterface
     * @throws LocalizedException
     */
    public function locate(string $identifier, ?int $blockId, string $verb): BlockInterface
    {
        $this->searchCriteriaBuilder->addFilter('identifier', $identifier);
        if ($blockId !== null) {
            $this->searchCriteriaBuilder->addFilter('block_id', $blockId);
        }
        $matches = array_values($this->blockRepository->getList($this->searchCriteriaBuilder->create())->getItems());

        if ($matches === []) {
            throw new LocalizedException($blockId === null
                ? __('No CMS block exists with identifier "%1".', $identifier)
                : __('No CMS block exists with identifier "%1" and block_id %2.', $identifier, $blockId));
        }

        if (count($matches) > 1) {
            throw new LocalizedException(__(
                'The identifier "%1" matches %2 CMS blocks (block_id %3). Pass block_id to say which one to %4.',
                $identifier,
                count($matches),
                implode(', ', array_map(static fn (BlockInterface $b): string => (string) $b->getId(), $matches)),
                $verb
            ));
        }

        return $matches[0];
    }

    /**
     * Schema fragment for the arguments that name a block.
     *
     * @param string $verb What the tool does to it, for the block_id description.
     * @return array<string, mixed>
     */
    public function schemaProperties(string $verb): array
    {
        return [
            'identifier' => [
                'type' => 'string',
                'description' => sprintf('Identifier of the block to %s.', $verb),
            ],
            'block_id' => [
                'type' => 'integer',
                'description' => 'Only needed when one identifier is shared by several blocks on '
                    . 'different store views.',
            ],
        ];
    }
}
