<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Cms;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Turns the page argument the CMS page tools accept into a loaded page.
 *
 * A page identifier is not unique — Magento allows the same identifier on
 * several pages assigned to different store views — so an ambiguous one is an
 * error naming the candidates rather than an arbitrary pick, the same way
 * update_cms_block already handles it.
 */
class PageLocator
{
    /**
     * @param PageRepositoryInterface $pageRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @param string|null $identifier
     * @param int|null $pageId
     * @return PageInterface
     * @throws LocalizedException
     */
    public function locate(?string $identifier, ?int $pageId): PageInterface
    {
        if ($pageId !== null) {
            try {
                return $this->pageRepository->getById($pageId);
            } catch (NoSuchEntityException) {
                throw new LocalizedException(__('No CMS page exists with page_id %1.', $pageId));
            }
        }

        if ($identifier === null) {
            throw new LocalizedException(
                __('Pass identifier (the page\'s url key, e.g. "about-us") or page_id.')
            );
        }

        $this->searchCriteriaBuilder->addFilter('identifier', $identifier);
        $matches = array_values($this->pageRepository->getList($this->searchCriteriaBuilder->create())->getItems());

        if ($matches === []) {
            throw new LocalizedException(__('No CMS page exists with identifier "%1".', $identifier));
        }

        if (count($matches) > 1) {
            throw new LocalizedException(__(
                'The identifier "%1" matches %2 CMS pages (page_id %3). Pass page_id to say which one.',
                $identifier,
                count($matches),
                implode(', ', array_map(static fn (PageInterface $p): string => (string) $p->getId(), $matches))
            ));
        }

        return $matches[0];
    }

    /**
     * Schema fragment for the arguments that name a page.
     *
     * @return array<string, mixed>
     */
    public function schemaProperties(): array
    {
        return [
            'identifier' => [
                'type' => 'string',
                'description' => 'The page\'s url key, e.g. "about-us". Either this or page_id is '
                    . 'required.',
            ],
            'page_id' => [
                'type' => 'integer',
                'description' => 'Numeric page id. Use this when an identifier is shared by several '
                    . 'pages on different store views.',
            ],
        ];
    }
}
