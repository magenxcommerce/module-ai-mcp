<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Gdpr;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Gdpr\Model\DsrRequest;
use Magenx\Gdpr\Model\DsrRequestFactory;
use Magenx\Gdpr\Model\ResourceModel\DsrRequest as RequestResource;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Turn down a data-subject request.
 *
 * Unlike {@see ApproveGdprRequest} this destroys nothing — it records a
 * decision and closes the row, so it needs no e-mail confirmation. What it does
 * need is a reason: a denial is the part of this process a regulator asks about,
 * and "denied by an administrator" answers nothing.
 */
class DenyGdprRequest extends AbstractTool
{
    /**
     * @param DsrRequestFactory $requestFactory
     * @param RequestResource $requestResource
     * @param DateTime $date
     * @param RequestProjector $projector
     */
    public function __construct(
        private readonly DsrRequestFactory $requestFactory,
        private readonly RequestResource $requestResource,
        private readonly DateTime $date,
        private readonly RequestProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'deny_gdpr_request';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Deny a pending data-subject request, recording why. Nothing is deleted or '
            . 'anonymized — the request is closed with your note against it. A reason is required, '
            . 'because a denial is the part of this process anyone auditing it will ask about. '
            . 'Only pending requests can be denied.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'request_id' => [
                    'type' => 'integer',
                    'description' => 'The pending request to deny; search_gdpr_requests reports it.',
                ],
                'note' => [
                    'type' => 'string',
                    'description' => 'Why it is being denied. Kept on the record permanently.',
                ],
            ],
            'required' => ['request_id', 'note'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Gdpr::requests';
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
    protected function isDestructive(): bool
    {
        // Records a decision; removes nothing.
        return false;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $requestId = $this->requireInt($arguments, 'request_id');
        $note = $this->requireString($arguments, 'note');

        $request = $this->requestFactory->create();
        $this->requestResource->load($request, $requestId);

        if (!$request->getId()) {
            throw new LocalizedException(__('No data-subject request exists with request_id %1.', $requestId));
        }

        if ($request->getData('status') !== DsrRequest::STATUS_PENDING) {
            throw new LocalizedException(__(
                'Request %1 is already "%2" and cannot be denied.',
                $requestId,
                (string) $request->getData('status')
            ));
        }

        $request->setData('status', DsrRequest::STATUS_DENIED);
        $request->setData('resolved_at', $this->date->gmtDate());
        $request->setData('admin_note', $note);
        $this->requestResource->save($request);

        return [
            'denied' => true,
            'tool' => $this->getName(),
        ] + $this->projector->toArray($request);
    }
}
