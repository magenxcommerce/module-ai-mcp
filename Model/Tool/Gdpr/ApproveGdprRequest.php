<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Gdpr;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Gdpr\Model\Anonymizer;
use Magenx\Gdpr\Model\DsrRequest;
use Magenx\Gdpr\Model\DsrRequestFactory;
use Magenx\Gdpr\Model\ResourceModel\DsrRequest as RequestResource;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Approve an erasure request, which anonymizes the customer.
 *
 * The most irreversible tool in this server. Approving does not mark a row and
 * move on: it overwrites the customer's name, e-mail, addresses, telephone,
 * order history and newsletter subscription with placeholders, permanently.
 *
 * The write switch and the confirm gate apply as everywhere else, but the
 * preview they produce names a `request_id` — not a person — so confirming it
 * tells an agent nothing about whose data is about to go. This tool therefore
 * asks for the customer's e-mail address as well, and refuses unless it matches
 * the request's own customer. An agent that reached for the wrong id cannot get
 * past it, and one that reached for the right id has had to look at who it
 * belongs to.
 *
 * The ordering below is the module's, copied deliberately rather than
 * reinvented: the row is moved out of "pending" *before* the anonymization
 * runs, so a failure between the two leaves the data destroyed and the request
 * visibly resolved, rather than destroyed and still pending — which invites a
 * second call that would re-run an irreversible operation with no record that
 * the first had already happened.
 */
class ApproveGdprRequest extends AbstractTool
{
    /**
     * @param DsrRequestFactory $requestFactory
     * @param RequestResource $requestResource
     * @param Anonymizer $anonymizer
     * @param DateTime $date
     * @param RequestProjector $projector
     */
    public function __construct(
        private readonly DsrRequestFactory $requestFactory,
        private readonly RequestResource $requestResource,
        private readonly Anonymizer $anonymizer,
        private readonly DateTime $date,
        private readonly RequestProjector $projector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'approve_gdpr_request';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Approve a pending data-subject request. This ANONYMIZES the customer permanently: '
            . 'their name, email, addresses, telephone, order history and newsletter subscription '
            . 'are overwritten with placeholders and cannot be recovered. As a guard against '
            . 'acting on the wrong row, you must pass customer_email and it must match the '
            . 'request\'s own customer — get_gdpr_request reports it. Only pending requests can be '
            . 'approved; export and anonymize requests are already completed when they reach this '
            . 'queue, so in practice this is the erasure path.';
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
                    'description' => 'The pending request to approve; search_gdpr_requests reports it.',
                ],
                'customer_email' => [
                    'type' => 'string',
                    'description' => 'The email of the customer this request belongs to, exactly as '
                        . 'get_gdpr_request reports it. Confirms you are erasing the person you '
                        . 'think you are; a mismatch refuses the call.',
                ],
                'note' => [
                    'type' => 'string',
                    'description' => 'Why it was approved, kept on the record. Recommended — it is '
                        . 'the only explanation anyone will find later.',
                ],
            ],
            'required' => ['request_id', 'customer_email'],
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
    public function execute(array $arguments): array
    {
        $requestId = $this->requireInt($arguments, 'request_id');
        $claimedEmail = $this->requireString($arguments, 'customer_email');

        $request = $this->requestFactory->create();
        $this->requestResource->load($request, $requestId);

        if (!$request->getId()) {
            throw new LocalizedException(__('No data-subject request exists with request_id %1.', $requestId));
        }

        if ($request->getData('status') !== DsrRequest::STATUS_PENDING) {
            throw new LocalizedException(__(
                'Request %1 is already "%2" and cannot be approved again.',
                $requestId,
                (string) $request->getData('status')
            ));
        }

        $customerId = (int) $request->getData('customer_id');
        $this->assertEmailMatches($customerId, $claimedEmail, $requestId);

        $note = $this->optionalString($arguments, 'note');

        $request->setData('status', DsrRequest::STATUS_APPROVED);
        $request->setData('resolved_at', $this->date->gmtDate());
        $request->setData('admin_note', $note ?? (string) __('Approved through the MCP server.'));
        $this->requestResource->save($request);

        $this->anonymizer->anonymizeCustomer($customerId);

        return [
            'approved' => true,
            'tool' => $this->getName(),
            'anonymized_customer_id' => $customerId,
            // The address as it was, because after this call nothing can
            // resolve it and the audit log line is the only record of who.
            'anonymized_customer_email' => $claimedEmail,
        ] + $this->projector->toArray($request);
    }

    /**
     * @param int $customerId
     * @param string $claimedEmail
     * @param int $requestId
     * @return void
     * @throws LocalizedException
     */
    private function assertEmailMatches(int $customerId, string $claimedEmail, int $requestId): void
    {
        $actual = $this->projector->emailOf($customerId);

        if ($actual === null) {
            throw new LocalizedException(__(
                'The customer behind request %1 no longer resolves, which usually means they have '
                . 'already been anonymized or deleted. Nothing was changed.',
                $requestId
            ));
        }

        // Case-insensitive because that is how Magento treats an address, but
        // otherwise exact: this is a confirmation step, not a search.
        if (strcasecmp($actual, $claimedEmail) !== 0) {
            throw new LocalizedException(__(
                'customer_email does not match the customer on request %1. Nothing was changed. '
                . 'Call get_gdpr_request and pass the address it reports.',
                $requestId
            ));
        }
    }
}
