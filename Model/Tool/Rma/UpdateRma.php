<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Rma;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Rma\Api\Data\RMAInterface;
use Magenx\Rma\Api\RMARepositoryInterface;
use Magenx\Rma\Api\ReasonRepositoryInterface;
use Magenx\Rma\Api\ResolutionTypeRepositoryInterface;
use Magenx\Rma\Api\StatusRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Change a return's status, reason or resolution type.
 *
 * Changing the status emails the customer. The module's repository compares the
 * stored status against the one being saved and dispatches
 * `rma_status_change_after` when they differ, which its own observer turns into
 * a status-change e-mail — so this is a customer-visible action, not a
 * bookkeeping one. Loading and mutating the existing record is also what makes
 * that comparison work: the repository needs the old status to notice a change.
 */
class UpdateRma extends AbstractTool
{
    /**
     * @param RmaLocator $locator
     * @param RMARepositoryInterface $rmaRepository
     * @param StatusRepositoryInterface $statusRepository
     * @param ReasonRepositoryInterface $reasonRepository
     * @param ResolutionTypeRepositoryInterface $resolutionTypeRepository
     * @param RmaProjector $projector
     */
    public function __construct(
        private readonly RmaLocator $locator,
        private readonly RMARepositoryInterface $rmaRepository,
        private readonly StatusRepositoryInterface $statusRepository,
        private readonly ReasonRepositoryInterface $reasonRepository,
        private readonly ResolutionTypeRepositoryInterface $resolutionTypeRepository,
        private readonly RmaProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'update_rma';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Change a return\'s status, reason or resolution type. Only the fields you pass are '
            . 'changed. Changing status_id emails the customer a status-change notification, so it '
            . 'is visible to them the moment this succeeds; setting it to the value it already has '
            . 'changes nothing and sends nothing. Each id is checked against the lookup tables '
            . 'first, so a wrong one is refused rather than stored.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => array_merge(
                $this->locator->schemaProperties(),
                [
                    'status_id' => [
                        'type' => 'integer',
                        'description' => 'New status; list_rma_statuses reports the ids. Emails the '
                            . 'customer when it differs from the current one.',
                    ],
                    'reason_id' => [
                        'type' => 'integer',
                        'description' => 'New return reason; list_rma_reasons reports the ids.',
                    ],
                    'resolution_type_id' => [
                        'type' => 'integer',
                        'description' => 'New resolution — refund, exchange and so on; '
                            . 'list_rma_resolution_types reports the ids.',
                    ],
                ]
            ),
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Rma::rma_manage';
    }

    /**
     * @inheritDoc
     */
    public function isWrite(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $rma = $this->locator->locate(
            $this->optionalString($arguments, 'increment_id'),
            $this->optionalInt($arguments, 'rma_id')
        );

        $statusBefore = $rma->getStatusId();
        $changed = $this->apply($rma, $arguments);

        if ($changed === []) {
            throw new LocalizedException(
                __('Nothing to update: pass status_id, reason_id or resolution_type_id.')
            );
        }

        $saved = $this->rmaRepository->save($rma);
        $statusChanged = $saved->getStatusId() !== $statusBefore;

        return [
            'updated' => true,
            'tool' => $this->getName(),
            'changed_fields' => $changed,
            'status_before' => $statusBefore,
            // True means the module's observer will have sent the customer a
            // status-change e-mail.
            'customer_notified' => $statusChanged,
        ] + $this->projector->toArray($saved);
    }

    /**
     * @param RMAInterface $rma
     * @param array<string, mixed> $arguments
     * @return string[]
     * @throws LocalizedException
     */
    private function apply(RMAInterface $rma, array $arguments): array
    {
        $changed = [];

        $statusId = $this->optionalInt($arguments, 'status_id');
        if ($statusId !== null) {
            $this->assertLookupExists(
                fn (): object => $this->statusRepository->get($statusId),
                'status_id',
                $statusId,
                'list_rma_statuses'
            );
            $rma->setStatusId($statusId);
            $changed[] = 'status_id';
        }

        $reasonId = $this->optionalInt($arguments, 'reason_id');
        if ($reasonId !== null) {
            $this->assertLookupExists(
                fn (): object => $this->reasonRepository->get($reasonId),
                'reason_id',
                $reasonId,
                'list_rma_reasons'
            );
            $rma->setReasonId($reasonId);
            $changed[] = 'reason_id';
        }

        $resolutionTypeId = $this->optionalInt($arguments, 'resolution_type_id');
        if ($resolutionTypeId !== null) {
            $this->assertLookupExists(
                fn (): object => $this->resolutionTypeRepository->get($resolutionTypeId),
                'resolution_type_id',
                $resolutionTypeId,
                'list_rma_resolution_types'
            );
            $rma->setResolutionTypeId($resolutionTypeId);
            $changed[] = 'resolution_type_id';
        }

        return $changed;
    }

    /**
     * Refuse an id that names no lookup row.
     *
     * These are plain foreign keys with no constraint behind them in the data
     * model, so an unknown id would save quite happily and leave the return
     * showing a blank status.
     *
     * @param callable $load
     * @param string $argument
     * @param int $id
     * @param string $listTool
     * @return void
     * @throws LocalizedException
     */
    private function assertLookupExists(callable $load, string $argument, int $id, string $listTool): void
    {
        try {
            $load();
        } catch (NoSuchEntityException) {
            throw new LocalizedException(
                __('No such %1: %2. %3 reports the ids.', $argument, $id, $listTool)
            );
        }
    }
}
