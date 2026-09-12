<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma;

use Magenx\Rma\Api\Data\RMAInterface;
use Magenx\Rma\Api\RMARepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Turns the RMA argument every return tool accepts into a loaded request.
 *
 * An agent works from what a customer quotes, which is the increment id, while
 * the repository's other operations take the numeric entity id. The module
 * offers a lookup for each, so this is only about choosing between them and
 * turning a wrong identifier into a readable error.
 */
class RmaLocator
{
    /**
     * @param RMARepositoryInterface $rmaRepository
     */
    public function __construct(
        private readonly RMARepositoryInterface $rmaRepository
    ) {
    }

    /**
     * @param string|null $incrementId
     * @param int|null $rmaId
     * @return RMAInterface
     * @throws LocalizedException
     */
    public function locate(?string $incrementId, ?int $rmaId): RMAInterface
    {
        if ($rmaId !== null) {
            try {
                return $this->rmaRepository->get($rmaId);
            } catch (NoSuchEntityException) {
                throw new LocalizedException(__('No return exists with rma_id %1.', $rmaId));
            }
        }

        if ($incrementId === null) {
            throw new LocalizedException(
                __('Pass increment_id (the return number the customer quotes) or rma_id.')
            );
        }

        try {
            return $this->rmaRepository->getByIncrementId($incrementId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No return exists with increment_id "%1".', $incrementId));
        }
    }

    /**
     * Schema fragment for the arguments that name a return.
     *
     * @return array<string, mixed>
     */
    public function schemaProperties(): array
    {
        return [
            'increment_id' => [
                'type' => 'string',
                'description' => 'The return number as the customer sees it. Either this or '
                    . 'rma_id is required.',
            ],
            'rma_id' => [
                'type' => 'integer',
                'description' => 'Numeric return id, as search_rma reports it.',
            ],
        ];
    }
}
