<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Gdpr;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Gdpr\Model\DsrRequestFactory;
use Magenx\Gdpr\Model\ResourceModel\DsrRequest as RequestResource;
use Magento\Framework\Exception\LocalizedException;

/**
 * One data-subject request, with the customer resolved.
 *
 * The tool to call before approving anything: the e-mail it reports is what
 * {@see ApproveGdprRequest} requires as confirmation, so reading it is the step
 * that makes an erasure deliberate rather than positional.
 */
class GetGdprRequest extends AbstractTool
{
    /**
     * @param DsrRequestFactory $requestFactory
     * @param RequestResource $requestResource
     * @param RequestProjector $projector
     */
    public function __construct(
        private readonly DsrRequestFactory $requestFactory,
        private readonly RequestResource $requestResource,
        private readonly RequestProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'get_gdpr_request';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one data-subject request in full, including the customer it belongs to and '
            . 'their current email address. Call this before approve_gdpr_request — the email it '
            . 'reports is what that tool requires as confirmation. A null customer_email means the '
            . 'customer no longer resolves, which usually means they were already anonymized.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'request_id' => ['type' => 'integer', 'description' => 'The request to read.'],
            ],
            'required' => ['request_id'],
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
    public function execute(array $arguments): array
    {
        $requestId = $this->requireInt($arguments, 'request_id');

        $request = $this->requestFactory->create();
        $this->requestResource->load($request, $requestId);

        if (!$request->getId()) {
            throw new LocalizedException(__('No data-subject request exists with request_id %1.', $requestId));
        }

        return $this->projector->toArray($request);
    }
}
